<?php
/**
 * Responder — turns search results into a conversational response payload.
 *
 * Responsible for:
 *   - Choosing the right framing based on confidence ("Here's your answer"
 *     vs "I'm not sure, but these might help" vs "Couldn't find anything").
 *   - Generating short highlighted snippets around matched query terms.
 *   - Structuring the final JSON payload the widget will consume.
 *
 * Confidence heuristic:
 *   HIGH   = top score >= 1.5 AND top is >= 1.5x the #2 score (or only 1 result)
 *   MEDIUM = top score >= 1.0
 *   LOW    = any results but below medium threshold
 *   NONE   = zero results
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Responder {

    const CONFIDENCE_HIGH   = 'high';
    const CONFIDENCE_MEDIUM = 'medium';
    const CONFIDENCE_LOW    = 'low';
    const CONFIDENCE_NONE   = 'none';

    /**
     * Plugin settings array (cached).
     *
     * @var array
     */
    private $settings;

    /**
     * Where a PDF's earlier passages come from, for the highlight-link check.
     *
     * Optional: without it PDF links simply carry no highlight, exactly as
     * before 0.9.22.
     *
     * @since 0.9.22
     * @var MBR_ISA_Indexer|null
     */
    private $indexer;

    /**
     * Folded page text per document, for the highlight uniqueness test.
     * Keyed by post ID; see pdf_earlier_pages().
     *
     * @since 0.9.22
     * @var array<int,array{through:int,pages:array}>
     */
    private $pdf_earlier_memo = [];

    /**
     * How far apart two query terms may sit and still be highlighted as one
     * span, in characters of text between them.
     *
     * @since 0.9.22
     */
    const PDF_HIGHLIGHT_GAP = 40;

    /**
     * Longest highlighted span, in characters.
     *
     * @since 0.9.22
     */
    const PDF_HIGHLIGHT_MAX_CHARS = 140;

    public function __construct( ?array $settings = null, ?MBR_ISA_Indexer $indexer = null ) {
        $this->settings = null === $settings ? get_option( 'mbr_isa_settings', [] ) : $settings;
        $this->indexer  = $indexer;
    }

    /**
     * Build the deep-link fragment for a result URL.
     *
     * Two different mechanisms, because the two viewers are different
     * software: HTML pages use the Text Fragments standard, while PDFs use
     * the long-standing PDF open parameters, which every mainstream viewer
     * honours. Neither can break a link — an unsupported directive is
     * ignored and the document simply opens at the start.
     *
     * @param string   $passage      Matching chunk text.
     * @param string[] $query_tokens Stemmed query tokens.
     * @param string   $post_type    Result post type.
     * @param int      $page_number  Page the chunk begins on (PDFs only).
     * @param int      $page_top     Estimated offset of the chunk on its page.
     * @param string   $phrase       Literal phrase for a quoted search, or ''.
     * @param int      $post_id      The result's post ID (PDF highlights only).
     * @return string Fragment including its leading '#', or ''.
     */
    private function build_deep_link( $passage, array $query_tokens, $post_type, $page_number, $page_top = 0, $phrase = '', $post_id = 0 ) {
        if ( 'attachment' === $post_type ) {
            if ( $page_number < 1 ) {
                return '';
            }

            // The stored page is the one the chunk *begins* on, but a chunk
            // of a few hundred words routinely spans a page boundary, so the
            // matched text is often on the next page. Count the page markers
            // that fall before the match to land on the right one.
            $pages_over = $this->pages_before_match( $passage, $query_tokens, $phrase );
            $page       = $page_number + $pages_over;

            /*
             * Highlight the found words, where the viewer can.
             *
             * Chrome's PDF viewer honours a text fragment — the same
             * `:~:text=` directive HTML results have carried since 0.8 — and
             * highlights the matching words when the file opens. A viewer
             * that does not understand it never sees it: browsers strip the
             * directive from the fragment before the document reads it, so
             * Firefox, Safari and Acrobat get the page number they always got.
             *
             * Three findings from testing Chrome's viewer decide the shape:
             *
             *  - The page number is the fallback, not a constraint. When the
             *    text matches, Chrome goes to the first place in the whole
             *    document where it appears, whatever page the link names. So
             *    the directive carries enough surrounding words to be unique
             *    up to the target page, which build_pdf_text_directive()
             *    checks against the index before emitting anything.
             *
             *  - `zoom` cannot ride alongside it. Chrome scrolls to the
             *    highlight and then applies the zoom, which leaves the same
             *    scroll offset pointing somewhere else at the new scale —
             *    page 21 instead of page 24 in testing, with the highlight
             *    off-screen. So a highlighted link names the page alone.
             *
             *  - When the text does not match — a PDF whose text layer the
             *    extractor could not read faithfully, say — Chrome falls back
             *    to the page number. The worst case is the top of the right
             *    page, which is where every PDF link went before 0.9.2.
             *
             * The `&` before the directive is for old PDF.js builds that read
             * the whole fragment as `&`-separated parameters: it keeps the
             * directive out of the page value, so the page still parses.
             *
             * @since 0.9.22
             */
            if ( $this->pdf_highlight_enabled() ) {
                $directive = $this->build_pdf_text_directive(
                    (string) $passage,
                    $query_tokens,
                    (string) $phrase,
                    (int) $post_id,
                    (int) $page
                );

                if ( '' !== $directive ) {
                    return '#page=' . (int) $page . '&:~:text=' . $directive;
                }
            }

            /*
             * A vertical offset only applies to the page the chunk starts on.
             * Where the match is on a later page, the chunk crossed a page
             * boundary and its text there begins at the top of that page, so
             * the top is already the right place to land.
             */
            if ( $pages_over > 0 || $page_top < 1 || ! $this->pdf_position_enabled() ) {
                return '#page=' . (int) $page;
            }

            /*
             * `zoom=scale,left,top` rather than `view=FitH,top`.
             *
             * Both can scroll, but FitH also fits the page to the window
             * width, which on a wide window zooms well past 100% — 163% was
             * reported on a normal desktop. That is a jarring change for
             * something the visitor only asked to scroll.
             *
             * zoom states the scale explicitly, so the page opens at a
             * predictable size. It defaults to 100 and is configurable, since
             * a site with dense or small-print documents may prefer more.
             *
             * The two parameters also use opposite origins: FitH takes PDF
             * user space, measured up from the bottom-left, while zoom
             * measures down from the top-left. page_top is stored in the
             * latter, so it is emitted as-is.
             */
            return '#page=' . (int) $page
                . '&zoom=' . $this->pdf_link_zoom() . ',0,' . (int) $page_top;
        }

        return $this->build_text_fragment( $passage, $query_tokens, $post_type );
    }

    /**
     * How many page boundaries fall before the matching text in a passage.
     *
     * @param string   $passage      Passage text, including page markers.
     * @param string[] $query_tokens Stemmed query tokens.
     * @param string   $phrase       Literal phrase for a quoted search, or ''.
     * @return int Zero when the match is on the passage's first page.
     */
    private function pages_before_match( $passage, array $query_tokens, $phrase = '' ) {
        $marker = MBR_ISA_PDF_Extractor::PAGE_MARKER;
        if ( false === strpos( (string) $passage, $marker ) ) {
            return 0;
        }

        $pos = $this->locate_match( (string) $passage, $query_tokens, $phrase );
        if ( null === $pos ) {
            return 0;
        }

        return substr_count( mb_substr( (string) $passage, 0, $pos ), $marker );
    }

    /**
     * Build a scroll-to-text fragment for a result URL.
     *
     * Produces the `#:~:text=` directive defined by the Text Fragments spec,
     * so a click lands on the passage that matched rather than the top of
     * the page. Every current browser engine supports it; anything that
     * does not simply ignores the directive and loads the page normally,
     * so this can never break a link.
     *
     * Two constraints shape the implementation:
     *
     *  - The phrase must match the *rendered* text of the page. We match on
     *    the stored passage, which came from post_content. Where a page
     *    builder renders from its own stored data rather than post_content,
     *    the phrase may not be found — in which case the browser falls back
     *    to the top of the page, which is the behaviour we had anyway.
     *
     *  - Hyphens and commas are directive delimiters and must be encoded
     *    even though they are URL-safe characters.
     *
     * @param string   $passage      The matching chunk's text.
     * @param string[] $query_tokens Stemmed query tokens.
     * @param string   $post_type    Result post type; attachments are skipped.
     * @return string Fragment beginning with '#:~:text=', or '' if unavailable.
     */
    private function build_text_fragment( $passage, array $query_tokens, $post_type = '' ) {
        // PDF viewers do not implement text fragments — they use their own
        // #page= / #search= parameters — so a directive would be dead weight
        // in the URL. Skipped rather than guessed at.
        if ( 'attachment' === $post_type ) {
            return '';
        }

        $passage = trim( (string) preg_replace( '/\s+/u', ' ', (string) $passage ) );
        if ( '' === $passage ) {
            return '';
        }

        $patterns = [];
        foreach ( $query_tokens as $stem ) {
            $stem = trim( (string) $stem );
            if ( '' === $stem || strlen( $stem ) < 3 ) {
                continue;
            }
            $patterns[] = preg_quote( $stem, '/' );
        }
        if ( empty( $patterns ) ) {
            return '';
        }

        $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';
        if ( ! preg_match_all( $regex, $passage, $all, PREG_OFFSET_CAPTURE ) ) {
            return '';
        }

        // Try each match in turn. The first occurrence of a query term is
        // often inside a heading, which is its own block and usually too
        // short to anchor on — in that case the next occurrence, in the
        // prose beneath, is the one worth linking to.
        foreach ( $all[0] as $match ) {
            // Work in characters, not bytes, so multibyte passages behave.
            $match_pos = mb_strlen( substr( $passage, 0, (int) $match[1] ) );

            // Anchor at the matched word and extend forwards, stopping at
            // the end of the sentence or the end of the block, whichever
            // comes first. Browsers will not match a range that crosses a
            // block boundary, and a mid-sentence range is perfectly valid.
            $tail  = mb_substr( $passage, $match_pos );
            $tail  = $this->truncate_at_boundary( $tail );
            $words = preg_split( '/ /u', $tail, -1, PREG_SPLIT_NO_EMPTY );

            // Too short to identify a location uniquely — try the next one.
            if ( count( $words ) < 4 ) {
                continue;
            }

            if ( count( $words ) <= 10 ) {
                $start = implode( ' ', $words );
                $end   = '';
            } else {
                // Longer run: use the textStart,textEnd range form so the
                // browser matches the span without needing every word
                // between to line up.
                $start = implode( ' ', array_slice( $words, 0, 6 ) );
                $end   = implode( ' ', array_slice( $words, 10, 4 ) );
                if ( '' === trim( $end ) ) {
                    $end = '';
                }
            }

            $fragment = '#:~:text=' . $this->encode_fragment_text( $start );
            if ( '' !== $end ) {
                $fragment .= ',' . $this->encode_fragment_text( $end );
            }

            return $fragment;
        }

        return '';
    }

    /**
     * Cut text at the end of its sentence or its block, whichever is first.
     *
     * Keeps a fragment inside a single block element, which is a hard
     * requirement: a browser will not match a range that spans one.
     *
     * @param string $text Text starting at the matched word.
     * @return string
     */
    private function truncate_at_boundary( $text ) {
        $marker = MBR_ISA_Tokeniser::BLOCK_MARKER;
        $len    = mb_strlen( $text );

        for ( $i = 0; $i < $len; $i++ ) {
            $ch = mb_substr( $text, $i, 1 );
            if ( $marker === $ch || in_array( $ch, [ '.', '!', '?', ':', ';' ], true ) ) {
                return trim( mb_substr( $text, 0, $i ) );
            }
        }

        return trim( $text );
    }

    /**
     * Find the character offset of the occurrence a snippet should open on.
     *
     * Where the query has several terms, the right place to open is where
     * most of them appear together — not wherever the first of them happens
     * to turn up. Searching "privacy, data, and rate limiting" against a
     * passage that says "rate" in its opening line and carries the whole
     * phrase two paragraphs later should land on the phrase; before 0.9.5 it
     * opened on the stray "rate" and the real match sat below the fold.
     *
     * So each occurrence is scored by how many *distinct* query terms fall
     * within a window around it, and the densest wins. Ties go to prose over
     * code, then to the earliest position, which keeps single-term queries
     * behaving exactly as they did.
     *
     * Shared by the snippet builder and the deep-link builder, so the passage
     * shown and the place linked to are always the same one — an improvement
     * here also sharpens which page a PDF result opens at.
     *
     * @param string   $passage      Passage text.
     * @param string[] $query_tokens Stemmed query tokens.
     * @return int|null Character offset, or null if nothing matched.
     */
    /**
     * Character offset of a literal phrase within a passage, or null.
     *
     * Matched the way the index matches it (see the phrase filter in the
     * indexer): case-insensitively, with any run of whitespace matching any
     * other, so a phrase broken across a line in a PDF is still found. The
     * two must agree — a passage the filter accepted but this could not
     * locate would be shown opening in the wrong place, which is the fault
     * this exists to fix.
     *
     * @since 0.9.7
     *
     * @param string $passage Passage text.
     * @param string $phrase  Literal phrase, unquoted.
     * @return int|null Character offset of the match.
     */
    private function locate_phrase( $passage, $phrase ) {
        $span = $this->locate_phrase_span( $passage, $phrase );

        return null === $span ? null : $span[0];
    }

    /**
     * Where a literal phrase starts and ends within a passage, or null.
     *
     * locate_phrase() with the far end as well, which a highlight needs and
     * a snippet does not. Offsets are in characters of the passage as given:
     * the folding below swaps each curly quote or no-break space for exactly
     * one character, so a count taken on the folded text holds for the
     * original too.
     *
     * @since 0.9.22
     *
     * @param string $passage Passage text.
     * @param string $phrase  Literal phrase, unquoted.
     * @return array{0:int,1:int}|null Start and end character offsets.
     */
    private function locate_phrase_span( $passage, $phrase ) {
        $spans = $this->phrase_spans( $passage, $phrase, false );

        return empty( $spans ) ? null : $spans[0];
    }

    /**
     * Every place a literal phrase appears in a text, as character offsets.
     *
     * The matching rules of locate_phrase(), which are the index's: case is
     * ignored, curly quotes and no-break spaces are folded, and any run of
     * whitespace matches any other.
     *
     * @since 0.9.22
     *
     * @param string $passage Text to search.
     * @param string $phrase  Literal phrase, unquoted.
     * @param bool   $all     Every occurrence, or only the first.
     * @return array<int,array{0:int,1:int}> Start and end offsets, in order.
     */
    private function phrase_spans( $passage, $phrase, $all = true ) {
        $fold = function ( $text ) {
            $text = str_replace(
                [ "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d", "\xc2\xa0" ],
                [ "'", "'", '"', '"', ' ' ],
                (string) $text
            );
            return $text;
        };

        $needle = trim( preg_replace( '/\s+/u', ' ', $fold( $phrase ) ) );
        if ( '' === $needle ) {
            return [];
        }

        // Whitespace in the phrase matches any run of whitespace in the
        // passage, which is what lets a phrase span a wrapped PDF line.
        $pattern = '/' . implode( '\s+', array_map(
            function ( $w ) { return preg_quote( $w, '/' ); },
            preg_split( '/ /', $needle, -1, PREG_SPLIT_NO_EMPTY )
        ) ) . '/iu';

        $folded = $fold( $passage );

        if ( ! preg_match_all( $pattern, $folded, $m, PREG_OFFSET_CAPTURE ) ) {
            return [];
        }

        $spans = [];
        foreach ( $m[0] as $hit ) {
            $start   = mb_strlen( substr( $folded, 0, (int) $hit[1] ) );
            $spans[] = [ $start, $start + mb_strlen( $hit[0] ) ];

            if ( ! $all ) {
                break;
            }
        }

        return $spans;
    }

    private function locate_match( $passage, array $query_tokens, $phrase = '' ) {
        /*
         * In phrase mode, the phrase is the answer to this question.
         *
         * A quoted query is tokenised like any other, which means its
         * stopwords are dropped before ranking — "How it works" reaches this
         * function as the single stem "work". Scoring occurrences of "work"
         * then picks whichever one happens to sit in the densest company,
         * and the snippet opens on "the two work together" several pages
         * from the phrase the visitor actually asked for. The deep link
         * shares this helper, so the PDF opened at the wrong page too.
         *
         * The literal phrase is unambiguous where it appears, so look for it
         * first. Falling through to the token scoring below is still right
         * when it is absent from this passage: a phrase result can be
         * matched on its title rather than its body.
         */
        $phrase = trim( (string) $phrase );
        if ( '' !== $phrase ) {
            $pos = $this->locate_phrase( $passage, $phrase );
            if ( null !== $pos ) {
                return $pos;
            }
        }

        $patterns = [];
        foreach ( $query_tokens as $stem ) {
            $stem = trim( (string) $stem );
            if ( '' === $stem || strlen( $stem ) < 2 ) {
                continue;
            }
            $patterns[] = preg_quote( $stem, '/' );
        }
        if ( empty( $patterns ) ) {
            return null;
        }

        $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';
        if ( ! preg_match_all( $regex, $passage, $all, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }

        // Character offset and which term matched, for every occurrence.
        $hits = [];
        foreach ( $all[0] as $i => $match ) {
            $hits[] = [
                'pos'  => mb_strlen( substr( $passage, 0, (int) $match[1] ) ),
                'term' => mb_strtolower( isset( $all[1][ $i ][0] ) ? $all[1][ $i ][0] : $match[0] ),
            ];
        }
        if ( empty( $hits ) ) {
            return null;
        }

        // How far either side of a candidate another term still counts as
        // being "together with" it. Roughly the width of the snippet window,
        // so the terms scored are the ones a visitor would actually see.
        $span = 120;

        $best       = null;
        $best_score = -1;
        $best_code  = true;

        foreach ( $hits as $hit ) {
            $terms = [];
            foreach ( $hits as $other ) {
                if ( abs( $other['pos'] - $hit['pos'] ) <= $span ) {
                    $terms[ $other['term'] ] = true;
                }
            }
            $score = count( $terms );

            $around  = mb_substr( $passage, max( 0, $hit['pos'] - 60 ), 160 );
            $is_code = $this->looks_like_code( $around );

            // Higher density wins. At equal density prose beats code, and
            // at equal footing the earliest position wins — which is simply
            // the first hit found, since $hits is in document order.
            if ( $score > $best_score
                || ( $score === $best_score && $best_code && ! $is_code ) ) {
                $best       = $hit['pos'];
                $best_score = $score;
                $best_code  = $is_code;
            }
        }

        return $best;
    }

    /**
     * Does this run of text look like source code rather than prose?
     *
     * Used only to choose where a snippet opens — never to exclude content,
     * which remains fully searchable and rankable. The test is the density
     * of characters common in code and rare in prose, plus a few
     * unmistakable digraphs.
     *
     * @param string $text Candidate window.
     * @return bool
     */
    private function looks_like_code( $text ) {
        $text = trim( (string) $text );
        $len  = mb_strlen( $text );
        if ( $len < 20 ) {
            return false;
        }

        $code_chars = preg_match_all( '/[$;{}\[\]=<>|\\\\]/u', $text );
        $digraphs   = preg_match_all( '#//|=>|->|::#', $text );

        return ( ( $code_chars + ( $digraphs * 3 ) ) / $len ) > 0.045;
    }

    /**
     * Percent-encode text for a text fragment directive.
     *
     * rawurlencode leaves '-' alone and encodes ',' — but both are directive
     * delimiters, so '-' is encoded explicitly here.
     *
     * @param string $text Plain text.
     * @return string
     */
    private function encode_fragment_text( $text ) {
        return str_replace( '-', '%2D', rawurlencode( $text ) );
    }

    /**
     * Build the final response payload.
     *
     * @param array    $search_results Output of MBR_ISA_Indexer::search().
     * @param string[] $query_tokens   Stemmed query tokens (for snippet highlighting).
     * @param string   $phrase         Literal phrase for a quoted search, or ''.
     * @param int      $limit_override Caller's own maximum, replacing the confidence
     *                                 cap. 0 (the default) uses the confidence cap.
     * @return array
     */
    public function format_search_response( array $search_results, array $query_tokens, $phrase = '', $limit_override = 0 ) {
        $results    = isset( $search_results['results'] ) ? $search_results['results'] : [];
        $confidence = $this->determine_confidence( $results );
        $message    = '' !== (string) $phrase
            ? $this->message_for_phrase( $confidence, (string) $phrase )
            : $this->message_for_confidence( $confidence );

        // Attach snippets (plus keep the raw excerpt) and, where possible, a
        // text fragment so the link lands on the matching passage rather than
        // the top of the page.
        $deep_links = ! isset( $this->settings['deep_link_results'] )
            || ! empty( $this->settings['deep_link_results'] );

        foreach ( $results as $i => $r ) {
            $results[ $i ]['snippet'] = $this->build_snippet(
                $r['excerpt'] ?? '',
                $query_tokens,
                (string) ( $r['post_type'] ?? '' ),
                (string) $phrase
            );

            /*
             * Further mentions in the same document.
             *
             * Each gets its own snippet and its own deep link, built by the
             * same helpers as the primary result, so a mention on page 34
             * opens at page 34 rather than at the top of the file. They are
             * secondary links on this result, not results in their own right:
             * the confidence caps below still count documents.
             *
             * A PDF's mention carries its page number as a label, which is
             * the one piece of context that makes a bare second link worth
             * clicking. Nothing else has an equivalent, so nothing else gets
             * a label rather than a made-up one.
             *
             * @since 0.9.18
             */
            $results[ $i ]['more'] = [];

            foreach ( (array) ( $r['extras'] ?? [] ) as $extra ) {
                $more_url = (string) ( $extra['url'] ?? '' );

                if ( '' === $more_url ) {
                    continue;
                }

                if ( $deep_links ) {
                    $fragment = $this->build_deep_link(
                        (string) ( $extra['excerpt'] ?? '' ),
                        $query_tokens,
                        (string) ( $r['post_type'] ?? '' ),
                        (int) ( $extra['page_number'] ?? 0 ),
                        (int) ( $extra['page_top'] ?? 0 ),
                        (string) $phrase,
                        (int) ( $r['post_id'] ?? 0 )
                    );

                    if ( '' !== $fragment ) {
                        $more_url .= $fragment;
                    }
                }

                $entry = [
                    'url'     => $more_url,
                    'snippet' => $this->build_snippet(
                        (string) ( $extra['excerpt'] ?? '' ),
                        $query_tokens,
                        (string) ( $r['post_type'] ?? '' ),
                        (string) $phrase
                    ),
                ];

                if ( 'pdf' === (string) ( $r['doc_kind'] ?? 'text' )
                    && (int) ( $extra['page_number'] ?? 0 ) > 0 ) {
                    /*
                     * The label has to be the page the link opens at, not the
                     * page the chunk started on.
                     *
                     * page_number records where the passage begins. A 250-word
                     * chunk routinely runs onto the next page, so a match in
                     * its later half is a page further on — which is exactly
                     * what pages_before_match() exists to work out, and what
                     * build_deep_link() above has already applied to the URL.
                     * Reading the raw column here made the label disagree with
                     * its own link whenever a chunk straddled a boundary, and
                     * a label that names the wrong page is worse than no label
                     * at all: it tells somebody the mention is somewhere it
                     * is not, and they check that page and conclude the search
                     * is broken.
                     *
                     * @since 0.9.18
                     */
                    $label_page = (int) $extra['page_number']
                        + $this->pages_before_match(
                            (string) ( $extra['excerpt'] ?? '' ),
                            $query_tokens,
                            (string) $phrase
                        );

                    $entry['label'] = sprintf(
                        /* translators: %d: page number within a PDF */
                        __( 'Page %d', 'mbr-isa' ),
                        $label_page
                    );
                }

                $results[ $i ]['more'][] = $entry;
            }

            // Internal only: the responder has taken what it needs. The
            // snippet is what the widget renders; the passage it was cut from
            // is up to 2,000 characters the client has no use for, and the
            // windowing deliberately chose not to show most of it.
            unset( $results[ $i ]['extras'], $results[ $i ]['excerpt'] );

            /*
             * Image results carry a thumbnail.
             *
             * An image's snippet is its alt text — a single line, often only
             * a few words. Rendered as a text result it looks like a page
             * with almost nothing on it, which is a poor showing for what may
             * be a perfectly good answer. The picture is the answer, so the
             * widget is given what it needs to show one.
             *
             * The 'medium' size rather than 'thumbnail': WordPress crops
             * thumbnails to a square by default, which decapitates people and
             * ruins anything wide. Medium preserves the aspect ratio, and the
             * widget scales it down in CSS.
             *
             * Falls back silently. A registered size can be missing on an
             * image uploaded before the theme declared it, and a result
             * without a thumbnail should still be a result.
             */
            if ( 'image' === (string) ( $r['doc_kind'] ?? '' ) ) {
                /*
                 * Label the result as an image.
                 *
                 * Added to the payload rather than prefixed onto the stored
                 * title, for two reasons. The title column is what the widget
                 * shows and what any other consumer of the REST response
                 * reads, so mutating it would put the word into data that is
                 * meant to be the page's own name. And the title field is
                 * indexed — writing "IMAGE" into it at index time would make
                 * 'image' a term attached to every picture on the site, which
                 * would then match any query mentioning the word.
                 *
                 * As a separate field the widget can render it as a badge,
                 * and an API consumer can use it, ignore it, or translate it,
                 * without any of them having to strip a prefix back off.
                 */
                $results[ $i ]['kind_label'] = __( 'IMAGE', 'mbr-isa' );

                $thumb = wp_get_attachment_image_src( (int) ( $r['post_id'] ?? 0 ), 'medium' );

                if ( is_array( $thumb ) && ! empty( $thumb[0] ) ) {
                    $results[ $i ]['thumbnail'] = (string) $thumb[0];
                    $results[ $i ]['thumb_w']   = (int) ( $thumb[1] ?? 0 );
                    $results[ $i ]['thumb_h']   = (int) ( $thumb[2] ?? 0 );
                }

                /*
                 * A larger rendition for the lightbox.
                 *
                 * 'large' rather than 'full': the original is whatever came
                 * off somebody's camera, which can be several megabytes and
                 * many times the size of any screen it will be shown on.
                 * Falls back to the original only when the large size was
                 * never generated — an image uploaded before the size was
                 * registered, or one smaller than the threshold.
                 */
                $large = wp_get_attachment_image_src( (int) ( $r['post_id'] ?? 0 ), 'large' );
                if ( ! is_array( $large ) || empty( $large[0] ) ) {
                    $large = wp_get_attachment_image_src( (int) ( $r['post_id'] ?? 0 ), 'full' );
                }

                if ( is_array( $large ) && ! empty( $large[0] ) ) {
                    $results[ $i ]['image_full']   = (string) $large[0];
                    $results[ $i ]['image_full_w'] = (int) ( $large[1] ?? 0 );
                    $results[ $i ]['image_full_h'] = (int) ( $large[2] ?? 0 );
                }

                // The alt text again, unhighlighted, so the widget can use it
                // as the img element's own alt attribute. A result that
                // describes a picture must not itself be undescribed.
                $results[ $i ]['image_alt'] = (string) get_post_meta(
                    (int) ( $r['post_id'] ?? 0 ),
                    '_wp_attachment_image_alt',
                    true
                );
            }

            if ( $deep_links ) {
                $fragment = $this->build_deep_link(
                    $r['excerpt'] ?? '',
                    $query_tokens,
                    $r['post_type'] ?? '',
                    (int) ( $r['page_number'] ?? 0 ),
                    (int) ( $r['page_top'] ?? 0 ),
                    (string) $phrase,
                    (int) ( $r['post_id'] ?? 0 )
                );
                if ( '' !== $fragment ) {
                    $results[ $i ]['url']           = ( $r['url'] ?? '' ) . $fragment;
                    $results[ $i ]['has_deep_link'] = true;
                }
            }
        }

        /*
         * Cap results by confidence level.
         *
         * Settable since 0.9.18. The defaults suit a small site; a
         * documentation library where one term legitimately appears across a
         * dozen guides wants a longer list, and that is a judgement about the
         * corpus rather than something the plugin can infer. Clamped to 1-10:
         * zero would mean a confidence level that finds results and shows
         * none, which is never what anybody wants.
         */
        $limit_setting = function ( $key, $default ) {
            $value = isset( $this->settings[ $key ] ) ? (int) $this->settings[ $key ] : $default;
            return max( 1, min( 10, $value ) );
        };

        $result_limits = [
            self::CONFIDENCE_HIGH   => $limit_setting( 'result_limit_high', 1 ),
            self::CONFIDENCE_MEDIUM => $limit_setting( 'result_limit_medium', 3 ),
            self::CONFIDENCE_LOW    => $limit_setting( 'result_limit_low', 3 ),
            self::CONFIDENCE_NONE   => 0,
        ];

        /*
         * A caller may state its own maximum, and where it does, that is the
         * one that applies.
         *
         * The confidence caps count documents in a *search* answer: at high
         * confidence result_limit_high is 1, because one confident answer is
         * the whole point of the level. Supplementary results beneath an
         * intent are a footnote to a different answer and are counted
         * separately, so inheriting the cap meant intent_supplementary_max
         * could never exceed one — and the better the supplementary hits
         * scored, the fewer of them were shown. CONFIDENCE_NONE still yields
         * nothing, since there is nothing to show.
         *
         * @since 0.9.19
         */
        $limit_override = max( 0, (int) $limit_override );
        $limit          = ( $limit_override > 0 && self::CONFIDENCE_NONE !== $confidence )
            ? $limit_override
            : $result_limits[ $confidence ];

        $results = array_slice( $results, 0, $limit );

        $suggestions = $this->suggestions_for_confidence( $confidence );

        return [
            'type'        => 'search_results',
            'confidence'  => $confidence,
            'phrase'      => (string) $phrase,
            'message'     => $message,
            'results'     => $results,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Build a response payload for an intent hit.
     *
     * @param array $intent
     * @return array
     */
    public function format_intent_response( array $intent ) {
        $response_html = (string) $intent['response'];

        return [
            'type'           => 'intent',
            'intent_id'      => $intent['id'],
            'intent_label'   => $intent['label'],
            // Plain-text fallback (used by logging and any consumer that
            // can't render HTML safely).
            'message'        => wp_strip_all_tags( $response_html ),
            // HTML-safe response. Already sanitised on save with wp_kses_post,
            // and stripped of any tags wp_kses_post wouldn't have allowed in
            // case the option was edited directly. The widget renders this
            // via innerHTML when present.
            'message_html'   => wp_kses_post( $response_html ),
            'message_format' => 'html',
            'results'        => [],
            'suggestions'    => [ __( 'Ask me something else about this site', 'mbr-isa' ) ],
        ];
    }

    /**
     * Build a response payload for empty/invalid queries.
     *
     * @return array
     */
    public function format_empty_query_response() {
        return [
            'type'        => 'empty_query',
            'confidence'  => self::CONFIDENCE_NONE,
            'message'     => __( 'What would you like to know? Try asking about a specific topic or service.', 'mbr-isa' ),
            'results'     => [],
            'suggestions' => [],
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Pick a confidence level based on score distribution.
     *
     * @param array $results Ranked results with 'score' keys.
     * @return string One of the CONFIDENCE_* constants.
     */
    private function determine_confidence( array $results ) {
        if ( empty( $results ) ) {
            return self::CONFIDENCE_NONE;
        }

        $top_score    = isset( $results[0]['score'] ) ? (float) $results[0]['score'] : 0.0;
        $second_score = isset( $results[1]['score'] ) ? (float) $results[1]['score'] : 0.0;

        /*
         * Dominant means the others are not answers, not merely that this one
         * is ahead.
         *
         * The ratio test alone was too easy to satisfy. A top score of 1.5
         * against a runner-up of 0.9 passed it, so the widget said "here is
         * what I found", showed one result, and discarded a 0.9 that would
         * have been shown as a perfectly good medium-confidence answer had the
         * leader scored slightly less. Searching a person's name showed that
         * plainly: an image matches a name on every weighted field at once —
         * filename into the title, alt text as both body and excerpt — so it
         * runs away with the score, and every document that actually
         * discusses the person was ranked, then sliced off behind it.
         *
         * A runner-up at or above the medium threshold is by definition worth
         * showing, so its presence now rules out high confidence. The leader
         * keeps its place at the top either way; what changes is that the
         * rest of the list survives.
         *
         * @since 0.9.18
         */
        $is_dominant = ( 1 === count( $results ) )
            || ( $second_score <= 0.0 )
            || ( $second_score < 1.0 && $top_score >= 1.5 * $second_score );

        if ( $top_score >= 1.5 && $is_dominant ) {
            return self::CONFIDENCE_HIGH;
        }
        if ( $top_score >= 1.0 ) {
            return self::CONFIDENCE_MEDIUM;
        }
        if ( $top_score > 0.0 ) {
            return self::CONFIDENCE_LOW;
        }
        return self::CONFIDENCE_NONE;
    }

    /**
     * Pick a message to accompany results at this confidence.
     *
     * @param string $confidence
     * @return string
     */
    private function message_for_confidence( $confidence ) {
        switch ( $confidence ) {
            case self::CONFIDENCE_HIGH:
                return __( 'Here is what I found on this site about that:', 'mbr-isa' );
            case self::CONFIDENCE_MEDIUM:
                return __( 'A few things on this site look relevant:', 'mbr-isa' );
            case self::CONFIDENCE_LOW:
                return __( 'I am not sure I found exactly what you are looking for, but these might help:', 'mbr-isa' );
            case self::CONFIDENCE_NONE:
            default:
                return __( 'I could not find anything specific about that on this site. Would you like to get in touch directly?', 'mbr-isa' );
        }
    }

    /**
     * Follow-up suggestions to offer the user.
     *
     * @param string $confidence
     * @return string[]
     */
    private function suggestions_for_confidence( $confidence ) {
        if ( self::CONFIDENCE_NONE === $confidence || self::CONFIDENCE_LOW === $confidence ) {
            return [
                __( 'Get in touch directly', 'mbr-isa' ),
                __( 'Try rephrasing your question', 'mbr-isa' ),
            ];
        }
        return [];
    }

    /**
     * Build a short snippet of text highlighting query terms.
     *
     * Looks for the first query-term hit in the excerpt and returns a
     * window around it with the matched terms wrapped in <mark>.
     *
     * @param string   $excerpt      Plain-text excerpt from the indexer.
     * @param string[] $query_tokens Stemmed query tokens.
     * @return string HTML-safe string with <mark> wrapping matches.
     */
    private function build_snippet( $excerpt, array $query_tokens, $post_type = '', $phrase = '' ) {
        /*
         * Block markers record where one block element ended and the next
         * began. They are structural metadata and a visitor must never see
         * the pilcrow itself — but the boundary it marks is real paragraph
         * structure, and a snippet that runs two paragraphs together as one
         * sentence is harder to read than it needs to be.
         *
         * So the marker becomes a newline here, survives windowing as an
         * ordinary character, and is turned into a <br> after escaping.
         * Page markers are dropped to a space instead: a page boundary in a
         * PDF is a layout event, not a change of thought, and breaking there
         * would imply a paragraph that is not present.
         *
         * PDF text has no block markers at all. Its own line breaks are hard
         * wraps from the original layout — a break every dozen words, mid
         * sentence — and were collapsed before the passage was stored.
         * Reinstating them would make snippets more ragged, not more
         * readable, so PDF snippets stay as continuous prose.
         */
        $excerpt = str_replace( MBR_ISA_PDF_Extractor::PAGE_MARKER, ' ', (string) $excerpt );
        $excerpt = str_replace( MBR_ISA_Tokeniser::BLOCK_MARKER, "\n", $excerpt );

        // Collapse runs of whitespace, but keep single newlines: a run of
        // markers has already been collapsed upstream, and any blank line
        // left here would open a gap in a two-line snippet.
        $excerpt = (string) preg_replace( '/[ \t]*\n[ \t\n]*/u', "\n", $excerpt );
        $excerpt = (string) preg_replace( '/[^\S\n]+/u', ' ', $excerpt );
        $excerpt = trim( $excerpt );
        if ( '' === $excerpt ) {
            return '';
        }

        // Highlight by matching token prefixes on word boundaries.
        // We use stems so a token like "plugin" will match "plugins", "plugin"
        // etc. in the raw text. Matching rule: word starts with the stem.
        $patterns = [];
        foreach ( $query_tokens as $stem ) {
            $stem = trim( (string) $stem );
            if ( '' === $stem || strlen( $stem ) < 2 ) {
                continue;
            }
            $patterns[] = preg_quote( $stem, '/' );
        }

        // Since 0.8.0 the stored excerpt is the full matching passage chunk
        // (up to 2,000 chars), so the first hit can sit anywhere in it. Cut
        // a window centred on the first hit, snapped to word boundaries,
        // before escaping and highlighting.
        $window = 240;

        if ( ! empty( $patterns ) ) {
            $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';

            // Prefer a match sitting in prose over one inside a code
            // sample; see locate_match().
            $char_pos = $this->locate_match( $excerpt, $query_tokens, $phrase );

            if ( null !== $char_pos ) {
                // Normally the window is centred on the match. But if what
                // precedes it is code — which happens when the only
                // occurrence of a term is inside a sample — leading with
                // that code wastes the snippet. Start at the match instead
                // and run forward into the prose that follows.
                $preceding = mb_substr( $excerpt, max( 0, $char_pos - 70 ), min( 70, $char_pos ) );
                $lead      = $this->looks_like_code( $preceding ) ? 0 : (int) floor( $window / 2 );

                $start = max( 0, $char_pos - $lead );

                // Snap the left edge forward to the next space so the
                // snippet never opens mid-word.
                if ( $start > 0 ) {
                    $space = mb_strpos( $excerpt, ' ', $start );
                    if ( false !== $space && $space < $char_pos ) {
                        $start = $space + 1;
                    }
                }

                $slice = mb_substr( $excerpt, $start, $window );

                // Trim a trailing part-word, then add ellipses where cut.
                if ( $start + $window < mb_strlen( $excerpt ) ) {
                    $last_space = mb_strrpos( $slice, ' ' );
                    if ( false !== $last_space && $last_space > (int) floor( $window / 2 ) ) {
                        $slice = mb_substr( $slice, 0, $last_space );
                    }
                    $slice .= '…';
                }
                if ( $start > 0 ) {
                    $slice = '…' . $slice;
                }

                $excerpt = $slice;
            } else {
                $excerpt = mb_substr( $excerpt, 0, $window );
            }
        } else {
            $excerpt = mb_substr( $excerpt, 0, $window );
        }

        /*
         * PDFs carry no block structure, so a snippet from one arrives as a
         * single unbroken run. Sentence boundaries are the only division the
         * text offers, so they stand in for paragraphs — artificial, but a
         * three-sentence snippet is markedly easier to scan broken up than
         * as one long line.
         */
        if ( 'attachment' === $post_type ) {
            $excerpt = $this->add_sentence_breaks( $excerpt );
        }

        /*
         * A quoted search marks the phrase, not its words.
         *
         * Marking stems works word by word, so the phrase came out in pieces:
         * its stopwords were never marked, since they are not query tokens,
         * and every other appearance of its content words lit up as well.
         * "Why those exclusions matter" showed as two marked words in an
         * unmarked line, which read as a partial match — when the visitor had
         * asked for, and got, the exact phrase. It also disagreed with the
         * PDF link, which highlights the whole phrase.
         *
         * Falls through to stem marking when the phrase is not in the window,
         * which happens when a result matched on its title.
         *
         * @since 0.9.22
         */
        if ( '' !== trim( (string) $phrase ) ) {
            $spans = $this->phrase_spans( $excerpt, (string) $phrase );

            if ( ! empty( $spans ) ) {
                $html   = '';
                $cursor = 0;

                foreach ( $spans as $span ) {
                    $html .= $this->snippet_piece( mb_substr( $excerpt, $cursor, $span[0] - $cursor ) )
                        . '<mark>'
                        . $this->snippet_piece( mb_substr( $excerpt, $span[0], $span[1] - $span[0] ) )
                        . '</mark>';
                    $cursor = $span[1];
                }

                return $html . $this->snippet_piece( mb_substr( $excerpt, $cursor ) );
            }
        }

        // Escape first so <mark> and <br> are the only HTML we introduce.
        $escaped = esc_html( $excerpt );

        // Paragraph boundaries, marked as newlines above. Done after escaping
        // so the tag cannot come from the source text.
        $escaped = str_replace( [ "\r\n", "\n" ], '<br>', $escaped );

        if ( empty( $patterns ) ) {
            return $escaped;
        }

        $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';

        $highlighted = preg_replace_callback( $regex, function ( $m ) {
            return '<mark>' . $m[0] . '</mark>';
        }, $escaped );

        return is_string( $highlighted ) ? $highlighted : $escaped;
    }

    /**
     * Escape a run of snippet text, turning its newlines into <br>.
     *
     * The same two steps build_snippet() applies to a whole snippet, for a
     * snippet assembled in pieces around phrase marks. Escaping comes first,
     * so the tag cannot come from the source text.
     *
     * @since 0.9.22
     *
     * @param string $text Plain text.
     * @return string HTML.
     */
    private function snippet_piece( $text ) {
        return str_replace( [ "\r\n", "\n" ], '<br>', esc_html( (string) $text ) );
    }

    /**
     * Framing for a quoted phrase search.
     *
     * Kept separate from message_for_confidence() because the two say
     * different things. An ordinary search hedges — these *look* relevant —
     * whereas a phrase result is a statement of fact: the phrase is on the
     * page or it is not. The confidence badge still varies with score, since
     * that reflects how prominently the phrase features, but the wording no
     * longer implies doubt about whether it is there at all.
     *
     * The "nothing found" case is the one that matters. A visitor who quoted
     * something and got the standard "I could not find anything about that"
     * would reasonably conclude the words are absent from the site, when the
     * truth may be that they are present but split across a chunk boundary,
     * or spelled differently. Saying which phrase was searched for, and
     * suggesting dropping the quotes, is more honest and more useful.
     *
     * @param string $confidence
     * @param string $phrase
     * @return string
     */
    private function message_for_phrase( $confidence, $phrase ) {
        if ( self::CONFIDENCE_NONE === $confidence ) {
            return sprintf(
                /* translators: %s: the exact phrase the visitor searched for */
                __( 'I could not find the exact phrase "%s" on this site. Searching without the quotes will look for the words separately.', 'mbr-isa' ),
                $phrase
            );
        }

        return sprintf(
            /* translators: %s: the exact phrase the visitor searched for */
            __( 'Here is what contains the exact phrase "%s":', 'mbr-isa' ),
            $phrase
        );
    }

    /**
     * Build a short result list to sit beneath an intent's canned answer.
     *
     * Shares the snippet and deep-link treatment of an ordinary search, but
     * applies a higher bar: only results at medium confidence or better are
     * returned. An intent has already answered the question well, so weak
     * extras beneath it read as the assistant hedging on an answer it was
     * sure of a line earlier.
     *
     * @param array    $search_results Output of MBR_ISA_Indexer::search().
     * @param string[] $query_tokens   Stemmed tokens, for snippet highlighting.
     * @param int      $max            Hard cap on how many to return.
     * @return array Result rows, possibly empty.
     */
    public function format_supplementary_results( array $search_results, array $query_tokens, $max = 3 ) {
        $results = isset( $search_results['results'] ) ? $search_results['results'] : [];
        if ( empty( $results ) ) {
            return [];
        }

        if ( self::CONFIDENCE_HIGH !== $this->determine_confidence( $results )
            && self::CONFIDENCE_MEDIUM !== $this->determine_confidence( $results ) ) {
            return [];
        }

        // Reuse the full formatting path. This keeps snippets, deep links and
        // escaping identical to a normal search — there is no second code
        // path to drift out of step. The maximum is passed *in* rather than
        // applied to what comes back, because the confidence caps travel with
        // the formatting: slicing afterwards left the high-confidence cap of
        // one already applied, so this could never return more than a single
        // row however well the extras scored.
        $max       = max( 1, (int) $max );
        $formatted = $this->format_search_response( $search_results, $query_tokens, '', $max );
        $rows      = isset( $formatted['results'] ) ? $formatted['results'] : [];

        return array_slice( $rows, 0, $max );
    }


    /**
     * Is vertical positioning of PDF links switched on?
     *
     * Separate from deep_link_results because the trade-off is different: a
     * text-fragment link on a post costs nothing when it misses, whereas a
     * PDF offset is an estimate that also overrides the reader's zoom. Some
     * sites will prefer the plain page link.
     *
     * @return bool
     */
    private function pdf_position_enabled() {
        return ! isset( $this->settings['pdf_link_position'] )
            || ! empty( $this->settings['pdf_link_position'] );
    }


    /**
     * Zoom percentage for a positioned PDF link.
     *
     * Any scrolling open parameter also sets a view, so a scale has to be
     * named. 100 keeps the document at its natural size, which is what most
     * readers default to.
     *
     * @return int
     */
    private function pdf_link_zoom() {
        $zoom = isset( $this->settings['pdf_link_zoom'] )
            ? (int) $this->settings['pdf_link_zoom']
            : 100;

        // Well outside this and a viewer is as likely to ignore the whole
        // fragment as to honour it, losing the page jump along with the zoom.
        return max( 25, min( 400, $zoom ) );
    }


    /**
     * Should PDF links highlight the found words?
     *
     * On by default. Separate from pdf_link_position because the trade-off
     * is separate: a highlighted link names the page alone, so where the text
     * cannot be matched in the viewer the reader lands at the top of the
     * right page rather than at the estimated position on it.
     *
     * @since 0.9.22
     *
     * @return bool
     */
    private function pdf_highlight_enabled() {
        return ! isset( $this->settings['pdf_link_highlight'] )
            || ! empty( $this->settings['pdf_link_highlight'] );
    }


    /**
     * The run of passage text to highlight, as character offsets.
     *
     * For a quoted search it is the phrase. Otherwise it opens on the
     * occurrence locate_match() chose — the same one the snippet opens on
     * and the page number was counted from — and stretches over any further
     * query terms that follow closely, so "rate limiting" lights up as one
     * phrase rather than as its first word. Only a term not already covered
     * extends it: repeats of the same word a sentence later would turn a
     * highlight into a paragraph.
     *
     * Never crosses a page boundary or the end of a sentence. Chrome confines
     * a highlight to a single page, so text either side of a boundary could
     * never match; and the gap between sentences is where a PDF keeps what
     * the extractor is most likely to have lost — a bullet, a heading
     * number, a dash — so a span reaching across one often cannot match.
     *
     * @since 0.9.22
     *
     * @param string   $passage      Passage text, with page markers.
     * @param string[] $query_tokens Stemmed query tokens.
     * @param string   $phrase       Literal phrase for a quoted search, or ''.
     * @return array{0:int,1:int}|null Start and end character offsets.
     */
    private function pdf_match_span( $passage, array $query_tokens, $phrase ) {
        $marker = MBR_ISA_PDF_Extractor::PAGE_MARKER;

        $phrase = trim( (string) $phrase );
        if ( '' !== $phrase ) {
            $span = $this->locate_phrase_span( $passage, $phrase );
            if ( null !== $span ) {
                return $span;
            }
        }

        $start = $this->locate_match( $passage, $query_tokens, $phrase );
        if ( null === $start ) {
            return null;
        }

        // Every occurrence, as locate_match() sees them.
        $patterns = [];
        foreach ( $query_tokens as $stem ) {
            $stem = trim( (string) $stem );
            if ( '' === $stem || strlen( $stem ) < 2 ) {
                continue;
            }
            $patterns[] = preg_quote( $stem, '/' );
        }
        if ( empty( $patterns ) ) {
            return null;
        }

        $regex = '/\b(' . implode( '|', $patterns ) . ')[\p{L}\p{N}]*/iu';
        if ( ! preg_match_all( $regex, $passage, $all, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }

        $end     = null;
        $covered = [];

        foreach ( $all[0] as $i => $match ) {
            $pos  = mb_strlen( substr( $passage, 0, (int) $match[1] ) );
            $len  = mb_strlen( $match[0] );
            $term = mb_strtolower( isset( $all[1][ $i ][0] ) ? $all[1][ $i ][0] : $match[0] );

            if ( $pos < $start ) {
                continue;
            }

            if ( null === $end ) {
                // The occurrence locate_match() chose. Anything else here
                // means the two disagree, and a guess is worse than nothing.
                if ( $pos !== $start ) {
                    return null;
                }
                $end              = $pos + $len;
                $covered[ $term ] = true;
                continue;
            }

            if ( isset( $covered[ $term ] ) ) {
                continue;
            }

            $gap = mb_substr( $passage, $end, $pos - $end );

            if ( ( $pos - $end ) > self::PDF_HIGHLIGHT_GAP
                || false !== mb_strpos( $gap, $marker )
                || preg_match( '/[.!?:;]\s/u', $gap )
                || ( $pos + $len - $start ) > self::PDF_HIGHLIGHT_MAX_CHARS ) {
                break;
            }

            $end              = $pos + $len;
            $covered[ $term ] = true;
        }

        return null === $end ? null : [ $start, $end ];
    }


    /**
     * Build the `text=` value that highlights the found words in a PDF.
     *
     * The directive names the found words themselves — the same words the
     * snippet marks — so they are what lights up, not the sentence around
     * them. On its own a word or two is rarely unique in a long document,
     * and Chrome highlights the *first* match in the file regardless of the
     * page the link names: "synonym expansion" aimed at page 33 opens on page
     * 3, where the phrase first appears. So the words are anchored with the
     * directive's prefix and suffix context — `before-,found words,-after` —
     * which Chrome requires to sit immediately either side of the match but
     * does not highlight.
     *
     * Context is added a word at a time until the text no longer appears on
     * any earlier page, checked against the indexed passages. If nothing
     * within reach is unique, no directive is emitted and the link falls
     * back to the page number. A wrong highlight is worse than none: it
     * overrides the page and sends the reader to the wrong place, where a
     * missing one merely leaves them at the right page.
     *
     * Every word of the directive must match the PDF exactly as Chrome reads
     * it, and the stored text is not always that. The extractor cannot decode
     * every font encoding, and what it cannot decode it drops — so "site’s
     * nonce" is stored as "site s nonce", a bullet between two list items
     * vanishes, and an em dash leaves only the space beside it. A directive
     * that reaches across one of those can never match. Testing against the
     * plugin's own user guide, where those characters are common, settled
     * four rules that keep the directive clear of them:
     *
     *  - Context stays inside the sentence. Bullets, heading numbers and
     *    section marks live between sentences.
     *  - Context stops at a lone fragment such as the "s" of a lost
     *    apostrophe, or at punctuation stranded between spaces where a quote
     *    mark used to be.
     *  - Every split of context between the two sides is tried, so one
     *    damaged side does not sink a directive the other could carry. At
     *    equal counts a balanced split goes first: on the user guide it
     *    matched slightly more often than either side alone.
     *  - A span of three or more words may instead be named by its ends
     *    (the textStart,textEnd range form), so whatever lies between them —
     *    including a dropped dash — does not have to match. Both forms are
     *    offered, and the candidate exposing the fewest word boundaries to
     *    exact matching wins.
     *
     * Two safety margins on top of the uniqueness check:
     *
     *  - The text must run to at least three words. The extractor removes
     *    running headers and footers before indexing, but the PDF still has
     *    them, so a short phrase that also appears in a header on page 1
     *    would pass the check and match there.
     *
     *  - Matching is folded the way Chrome folds it — case, curly quotes,
     *    no-break spaces, and any run of whitespace — and tested as a
     *    substring rather than on word boundaries, so the check errs towards
     *    adding context, never towards trusting too little.
     *
     * @since 0.9.22
     *
     * @param string   $passage      Passage text, with page markers.
     * @param string[] $query_tokens Stemmed query tokens.
     * @param string   $phrase       Literal phrase for a quoted search, or ''.
     * @param int      $post_id      Attachment ID.
     * @param int      $target_page  Page the link opens at.
     * @return string Encoded directive value (after `text=`), or ''.
     */
    private function build_pdf_text_directive( $passage, array $query_tokens, $phrase, $post_id, $target_page ) {
        if ( null === $this->indexer || $post_id < 1 || $target_page < 1 ) {
            return '';
        }

        $marker = MBR_ISA_PDF_Extractor::PAGE_MARKER;

        $span = $this->pdf_match_span( $passage, $query_tokens, $phrase );
        if ( null === $span ) {
            return '';
        }
        list( $start, $end ) = $span;

        // The page the span sits on, as a stretch of the passage.
        $seg_start = mb_strrpos( mb_substr( $passage, 0, $start ), $marker );
        $seg_start = ( false === $seg_start ) ? 0 : $seg_start + 1;
        $seg_end   = mb_strpos( $passage, $marker, $end );
        $seg_end   = ( false === $seg_end ) ? mb_strlen( $passage ) : $seg_end;

        $before_text = mb_substr( $passage, $seg_start, $start - $seg_start );
        $found_text  = mb_substr( $passage, $start, $end - $start );
        $after_text  = mb_substr( $passage, $end, $seg_end - $end );

        $found = preg_split( '/\s+/u', trim( $found_text ), -1, PREG_SPLIT_NO_EMPTY );
        if ( empty( $found ) ) {
            return '';
        }

        // Words that may serve as context, nearest first, with the text
        // between each and the match kept exactly as stored. See below.
        $prefix_pool = $this->pdf_context_words( $before_text, true );
        $suffix_pool = $this->pdf_context_words( $after_text, false );

        /*
         * The forms the directive can take. Each is tried with every amount
         * of context below, and the unique candidate that asks least of the
         * extracted text wins (see the ordering further down).
         *
         * Exact names every found word. That is the most text to be unique
         * on its own, and the only form that can take context after the
         * match as well as before it.
         *
         * Range names a span of three or more words by its ends — the first
         * word or two, then the last — so whatever lies between need not
         * match. That gets a cluster such as "feedback — the signed token"
         * past a dash the extractor dropped. But its head is short, so it
         * leans on context before the match, and a heading has none: the
         * sentence ahead of it ends in a full stop. Offered alone for longer
         * spans, it left most quoted searches for a heading with no highlight
         * at all — 28 of 40 in testing — which is why both forms are offered.
         *
         * A range's end must be the first of its kind after the start:
         * Chrome takes the first textEnd it finds, and a range that stops
         * early is the wrong highlight on the right page.
         */
        $forms = [
            [ 'start' => $found, 'end' => [], 'head' => $found_text ],
        ];

        if ( count( $found ) >= 3 ) {
            $end_words = [];
            $tail      = $this->fold_for_pdf_match( implode( ' ', array_slice( $found, 1 ) ) );

            foreach ( [ 1, 2 ] as $n ) {
                $candidate = array_slice( $found, -$n );
                $probe     = $this->fold_for_pdf_match( implode( ' ', $candidate ) );

                if ( '' !== $probe && strpos( $tail, $probe ) === strlen( $tail ) - strlen( $probe ) ) {
                    $end_words = $candidate;
                    break;
                }
            }

            if ( ! empty( $end_words ) ) {
                foreach ( [ 1, 2 ] as $n ) {
                    // Something must be left between the two ends.
                    if ( count( $found ) - $n - count( $end_words ) < 1 ) {
                        continue;
                    }
                    $start_words = array_slice( $found, 0, $n );
                    $forms[]     = [
                        'start' => $start_words,
                        'end'   => $end_words,
                        'head'  => implode( ' ', $start_words ),
                    ];
                }
            }
        }

        $earlier = $this->pdf_earlier_pages( $post_id, $target_page );
        if ( null === $earlier ) {
            return '';
        }

        /*
         * Every form with every amount of context, ordered by how many word
         * boundaries must match the PDF exactly — prefix and suffix words,
         * and the gaps inside whatever the form names in full. Those gaps
         * are where a dropped dash or quote mark hides, so fewer is safer.
         * Ties go to fewer context words, then to the form order above, then
         * to the plan's own order.
         *
         * Measured on the user guide against two simpler orders (fewest
         * context words first, with exact or with range preferred): this one
         * highlighted 232 of 254 links against 231 for either.
         */
        $candidates = [];
        foreach ( $forms as $f => $form ) {
            $max_suffix = empty( $form['end'] ) ? count( $suffix_pool ) : 0;

            foreach ( $this->pdf_context_plan( count( $prefix_pool ), $max_suffix ) as $rank => $plan ) {
                $joins = ( count( $form['start'] ) - 1 )
                    + ( empty( $form['end'] ) ? 0 : count( $form['end'] ) - 1 )
                    + $plan[0] + $plan[1];

                $candidates[] = [ $joins, $plan[0] + $plan[1], $f, $rank, $plan[0], $plan[1] ];
            }
        }
        sort( $candidates );

        foreach ( $candidates as $candidate ) {
            list( , , $f, , $np, $ns ) = $candidate;

            $start_words = $forms[ $f ]['start'];
            $end_words   = $forms[ $f ]['end'];

            $prefix = array_slice( $prefix_pool, 0, $np );
            $suffix = array_slice( $suffix_pool, 0, $ns );

            // The needle, as the stored text has it: what Chrome looks for
            // first, which is the prefix and textStart. In the range form the
            // rest is matched afterwards, on the same page, so uniqueness
            // rests on this part alone. Nearest prefix word is first in the
            // pool, so the outermost is the last one taken.
            $prefix_text = $np > 0 ? $prefix[ $np - 1 ]['from'] : '';
            $suffix_text = $ns > 0 ? $suffix[ $ns - 1 ]['from'] : '';
            $needle      = $prefix_text . $forms[ $f ]['head'] . $suffix_text;
            $needle_size = $np + count( $start_words ) + $ns;

            if ( $needle_size < 3 || mb_strlen( trim( $needle ) ) < 18 ) {
                continue;
            }

            // This page's text ahead of the needle, for the same-page test.
            $lead = (string) mb_substr( $before_text, 0, mb_strlen( $before_text ) - mb_strlen( $prefix_text ) );

            if ( $this->pdf_text_appears_earlier( $needle, $lead, $earlier ) ) {
                continue;
            }

            $directive = '';

            if ( $np > 0 ) {
                $words = array_reverse( array_column( $prefix, 'word' ) );
                $directive .= $this->encode_fragment_text( implode( ' ', $words ) ) . '-,';
            }

            $directive .= $this->encode_fragment_text( implode( ' ', $start_words ) );

            if ( ! empty( $end_words ) ) {
                $directive .= ',' . $this->encode_fragment_text( implode( ' ', $end_words ) );
            }

            if ( $ns > 0 ) {
                $directive .= ',-' . $this->encode_fragment_text( implode( ' ', array_column( $suffix, 'word' ) ) );
            }

            return $directive;
        }

        return '';
    }


    /**
     * The words that may serve as context on one side of a match.
     *
     * Nearest first. Each entry carries the word and, under 'from', the
     * stored text running from that word to the match (or from the match to
     * it), exactly as written, so the uniqueness test can be run on what the
     * page actually says rather than on words re-joined with spaces. That
     * matters: context often touches the match with no space at all —
     * "expansion." ends on a full stop, "(synonym" opens on a bracket — and
     * re-joined they become "expansion ." and "( synonym", which appear
     * nowhere, and a needle that appears nowhere passes every check.
     *
     * Collection stops at the first word that cannot be trusted to match the
     * PDF (see build_pdf_text_directive()):
     *
     *  - the end of a sentence: walking back, a word ending in . ! ? : or ;
     *    is not taken; walking forward, it is taken and is the last;
     *  - a lone "s", "t", "re", "ll", "ve", "d" or "m" — what is left of a
     *    contraction or possessive whose apostrophe was dropped;
     *  - punctuation standing alone between spaces, which is where a quote
     *    mark or section sign used to be.
     *
     * @since 0.9.22
     *
     * @param string $text      Text on one side of the match, same page.
     * @param bool   $backwards True for the text before the match.
     * @return array<int,array{word:string,from:string}>
     */
    private function pdf_context_words( $text, $backwards ) {
        $text = (string) $text;

        if ( ! preg_match_all( '/\S+/u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
            return [];
        }

        $tokens = $m[0];
        if ( $backwards ) {
            $tokens = array_reverse( $tokens );
        }

        $out   = [];
        $bytes = strlen( $text );

        foreach ( $tokens as $k => $token ) {
            $word = (string) $token[0];
            $at   = (int) $token[1];

            // Touching the match, with no space between: "(" before it, or
            // the full stop after it.
            $touching = $backwards ? ( $at + strlen( $word ) === $bytes ) : ( 0 === $at );

            // Stranded punctuation: a token of nothing but punctuation that
            // does not touch the match is standing between spaces, which in
            // prose it almost never does on its own. A dash is the exception.
            if ( ! $touching
                && preg_match( '/^[\p{P}\p{S}]+$/u', $word )
                && ! preg_match( '/^[\x{2010}-\x{2015}\-]+$/u', $word ) ) {
                break;
            }

            // What remains of a contraction or possessive.
            if ( $at > 0 && ctype_space( $text[ $at - 1 ] )
                && preg_match( '/^(s|t|d|m|ll|re|ve)\p{P}*$/iu', $word ) ) {
                break;
            }

            $ends_sentence = (bool) preg_match( '/[.!?:;][\p{Pe}\p{Pf}"\']*$/u', $word );

            if ( $backwards && $ends_sentence ) {
                break;
            }

            $out[] = [
                'word' => $word,
                'from' => $backwards ? (string) substr( $text, $at ) : (string) substr( $text, 0, $at + strlen( $word ) ),
            ];

            if ( ! $backwards && $ends_sentence ) {
                break;
            }

            if ( count( $out ) >= 6 ) {
                break;
            }
        }

        return $out;
    }


    /**
     * The order in which to try amounts of context, as [prefix, suffix].
     *
     * Fewest words first, since every word is another chance of meeting
     * something the extractor read differently; at equal totals, balanced
     * before lopsided, then prefix before suffix. Capped at eight words.
     *
     * @since 0.9.22
     *
     * @param int $max_prefix Prefix words available.
     * @param int $max_suffix Suffix words available.
     * @return array<int,array{0:int,1:int}>
     */
    private function pdf_context_plan( $max_prefix, $max_suffix ) {
        $plan = [];

        for ( $p = 0; $p <= $max_prefix; $p++ ) {
            for ( $s = 0; $s <= $max_suffix; $s++ ) {
                if ( $p + $s <= 8 ) {
                    $plan[] = [ $p, $s ];
                }
            }
        }

        usort( $plan, function ( $a, $b ) {
            return [ $a[0] + $a[1], abs( $a[0] - $a[1] ), -$a[0] ]
                <=> [ $b[0] + $b[1], abs( $b[0] - $b[1] ), -$b[0] ];
        } );

        return $plan;
    }


    /**
     * A PDF's text on every page before the target, folded for matching.
     *
     * Folded once per document and target page rather than once per
     * candidate: a directive may try a few dozen amounts of context, and
     * each is then a plain substring test.
     *
     * @since 0.9.22
     *
     * @param int $post_id     Attachment ID.
     * @param int $target_page Page the link opens at.
     * @return string[]|null Folded page texts, or null when the document's
     *                       passages could not be read.
     */
    private function pdf_earlier_pages( $post_id, $target_page ) {
        $post_id     = (int) $post_id;
        $target_page = (int) $target_page;

        /*
         * Memoised per document, not per target page. A result and its
         * further mentions aim at several pages of the same file, and folding
         * is the costly part, so each passage is folded once per request and
         * the cache only grows when a later page is asked for.
         */
        $cached = $this->pdf_earlier_memo[ $post_id ] ?? null;

        if ( null === $cached || $cached['through'] < $target_page ) {
            $rows = $this->indexer->pdf_passages_through_page( $post_id, $target_page );
            if ( empty( $rows ) ) {
                return null;
            }

            $marker = MBR_ISA_PDF_Extractor::PAGE_MARKER;
            $pages  = [];

            foreach ( $rows as $row ) {
                foreach ( explode( $marker, (string) $row['text'] ) as $offset => $text ) {
                    $page = (int) $row['page'] + $offset;
                    if ( $page > $target_page ) {
                        break;
                    }
                    $pages[] = [ $page, $this->fold_for_pdf_match( $text ) ];
                }
            }

            if ( count( $this->pdf_earlier_memo ) > 20 ) {
                $this->pdf_earlier_memo = [];
            }

            $cached = [ 'through' => $target_page, 'pages' => $pages ];
            $this->pdf_earlier_memo[ $post_id ] = $cached;
        }

        $out = [];
        foreach ( $cached['pages'] as $entry ) {
            if ( $entry[0] < $target_page ) {
                $out[] = $entry[1];
            }
        }

        return $out;
    }


    /**
     * Would Chrome find this text before the occurrence being linked to?
     *
     * Two places it could be: any page before the target, anywhere in the
     * indexed passages; or earlier on the target page itself, ahead of the
     * match. The second is tested on this passage only. Text further up the
     * same page that lives in the previous chunk is not looked for, because
     * chunks overlap and an occurrence there cannot be told apart from this
     * one — and it would still put the reader on the right page.
     *
     * @since 0.9.22
     *
     * @param string   $needle  Text the directive would match, as written.
     * @param string   $lead    This page's text immediately ahead of it.
     * @param string[] $earlier From pdf_earlier_pages().
     * @return bool
     */
    private function pdf_text_appears_earlier( $needle, $lead, array $earlier ) {
        $needle = $this->fold_for_pdf_match( $needle );

        if ( '' === $needle ) {
            return true;
        }

        // Earlier on the same page. The needle sits at the end of this
        // haystack by construction — the two are adjacent slices of the
        // passage — so finding it anywhere sooner means a second occurrence
        // ahead of ours.
        $haystack = $this->fold_for_pdf_match( $lead . $needle );
        $first    = strpos( $haystack, $needle );
        if ( false !== $first && $first < strlen( $haystack ) - strlen( $needle ) ) {
            return true;
        }

        foreach ( $earlier as $text ) {
            if ( false !== strpos( $text, $needle ) ) {
                return true;
            }
        }

        return false;
    }


    /**
     * Fold text the way Chrome's PDF text search compares it.
     *
     * Case-insensitive; curly quotes equal straight ones (confirmed in
     * testing: a directive written with straight quotes highlights curly
     * ones); a no-break space is a space; and any run of whitespace equals
     * any other, which is what lets a directive match across a line break.
     *
     * @since 0.9.22
     *
     * @param string $text Text.
     * @return string
     */
    private function fold_for_pdf_match( $text ) {
        $text = str_replace(
            [ "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d", "\xc2\xa0" ],
            [ "'", "'", '"', '"', ' ' ],
            (string) $text
        );

        return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $text ) ) );
    }


    /**
     * Insert line breaks at sentence boundaries.
     *
     * Used for PDF snippets, which have no paragraph structure to fall back
     * on. A break goes after . ! or ? when what follows looks like the start
     * of a new sentence.
     *
     * The guards matter more than the rule. Technical prose is full of full
     * stops that end nothing: section references (§ 11.5.), version numbers,
     * initials, and abbreviations such as e.g. and Fig. Breaking on those
     * produces a snippet more broken up than the original, which is the
     * opposite of the point. Decimals need no guard — a digit is not
     * followed by a space — but the rest do.
     *
     * A very short trailing fragment is left attached rather than stranded
     * on a line of its own.
     *
     * @param string $text Windowed snippet text, whitespace already collapsed.
     * @return string Same text with newlines inserted at sentence ends.
     */
    private function add_sentence_breaks( $text ) {
        $text = (string) $text;
        if ( '' === $text ) {
            return $text;
        }

        /*
         * Done by hand rather than with one regex. The obvious approach — a
         * negative lookbehind listing the abbreviations — will not compile,
         * because PCRE requires lookbehind assertions to be a fixed length
         * and "e.g" and "approx" are not. So candidates are found first and
         * each is then judged on the text preceding it.
         */
        if ( ! preg_match_all( '/[.!?] +(?=[A-Z\x{201C}"\x{2018}\'(\[])/u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
            return $text;
        }

        $abbr = [
            'e.g', 'i.e', 'etc', 'vs', 'cf', 'approx', 'no', 'fig', 'figs',
            'ch', 'sec', 'pp', 'mr', 'mrs', 'ms', 'dr', 'prof', 'st', 'jr',
            'sr', 'inc', 'ltd', 'co', 'al', 'ed', 'eds', 'vol',
        ];

        $cuts = [];
        foreach ( $m[0] as $match ) {
            $offset = (int) $match[1];

            // The word ending at this full stop.
            $before = substr( $text, max( 0, $offset - 14 ), min( 14, $offset ) );
            if ( ! preg_match( '/([\p{L}\p{N}.]+)$/u', $before, $w ) ) {
                continue;
            }
            $word = rtrim( $w[1], '.' );

            // A single capital is an initial — "R. Palmer" is one name.
            if ( 1 === mb_strlen( $word ) && preg_match( '/^\p{Lu}$/u', $word ) ) {
                continue;
            }

            if ( in_array( mb_strtolower( $word ), $abbr, true ) ) {
                continue;
            }

            // Cut after the punctuation, before the space.
            $cuts[] = $offset + 1;
        }

        if ( empty( $cuts ) ) {
            return $text;
        }

        $lines = [];
        $prev  = 0;
        foreach ( $cuts as $cut ) {
            $lines[] = substr( $text, $prev, $cut - $prev );
            $prev    = $cut;
        }
        $lines[] = substr( $text, $prev );

        // Re-join anything too short to earn a line of its own. The bar is
        // deliberately low: "Others are skipped." is a real sentence and
        // belongs on its own line, whereas a stray "OK." does not.
        $out = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( ! empty( $out ) && mb_strlen( $line ) < 15 ) {
                $out[ count( $out ) - 1 ] .= ' ' . $line;
                continue;
            }
            $out[] = $line;
        }

        return implode( "\n", $out );
    }


}