<?php

namespace codemonauts\glossary\services;

use codemonauts\glossary\elements\Glossary;
use codemonauts\glossary\elements\Term;
use codemonauts\glossary\Glossary as Plugin;
use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\Html;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use Exception;
use Twig\Error\SyntaxError;
use yii\base\Component;
use function Symfony\Component\String\s;

class Terms extends Component
{
    protected string $renderedTerms = '';

    protected array $usedTerms = [];

    /**
     * Returns all terms to search for.
     *
     * @param Term $term
     *
     * @return array
     */
    public function parseTerms(Term $term): array
    {
        $terms = [
            $term->term,
        ];

        if (!empty($term->synonyms)) {
            $synonyms = explode(',', $term->synonyms);
            $terms = array_merge($terms, $synonyms);
        }

        return ArrayHelper::filterEmptyStringsFromArray($terms);
    }

    /**
     * Search and replace the terms in a text based on a glossary.
     *
     * @param string $text The text so search and replace.
     * @param Glossary $glossary The glossary to use.
     *
     * @return string
     */
    public function renderTerms(string $text, Glossary $glossary): string
    {
        return Plugin::getInstance()->useAccessiblePopups()
            ? $this->renderTermsAccessible($text, $glossary)
            : $this->renderTermsLegacy($text, $glossary);
    }

    /**
     * The original matcher, byte-for-byte, so that with the setting off an
     * upgrade changes nothing. Its bugs (spans spliced into attribute values,
     * spans nested in links, a dead Entry query per match) are deliberate here
     * and fixed in renderTermsAccessible(). Do not tidy it.
     *
     * @param string $text
     * @param Glossary $glossary
     *
     * @return string
     */
    protected function renderTermsLegacy(string $text, Glossary $glossary): string
    {
        $view = Craft::$app->getView();
        $originalText = $text;

        try {
            $replacements = [];
            $terms = Term::find()->glossary($glossary)->all();

            foreach ($terms as $term) {
                $index = 0;
                $words = $this->parseTerms($term);

                foreach ($words as $word) {
                    $word = preg_quote($word, '/');
                    if ($term->matchSubstring) {
                        $pattern = '/' . $word . '/';
                    } else {
                        $pattern = "/\b" . $word . "\b/";
                    }
                    if (!$term->caseSensitive) {
                        $pattern .= 'i';
                    }
                    $text = s($text)->replaceMatches($pattern, function ($matches) use ($term, &$replacements, &$index, $view, $glossary) {
                        try {
                            $replacement = sprintf(
                                '<span class="underline glossary" data-glossary-term="term-%d">%s</span>',
                                $term->id,
                                htmlspecialchars($matches[0], ENT_QUOTES, 'UTF-8')
                            );
                        } catch (SyntaxError $e) {
                            Craft::error($e->getMessage(), 'glossary');
                            $replacement = false;
                        }

                        if ($replacement === false) {
                            return $term;
                        }

                        $token = $term->uid . '-' . $index++;
                        $replacements[$token] = $replacement;

                        /**
                         * @deprecated Remove field values with version 2.0 and only use term to access all fields.
                         */
                        $variables = $term->getFieldValues();
                        $variables['term'] = $term;

                        // Craft 5 logic: Use Entry to fetch additional data
                        if (class_exists('craft\elements\Entry')) {
                            $searchString = 'title:"' . $term . '"';
                            $entry = \craft\elements\Entry::find()
                                ->section('glossary')
                                ->search($searchString)
                                ->one();

                            if ($entry) {
                                $variables['caption'] = $entry->caption ?? '';
                            }
                        }

                        try {
                            $this->usedTerms[$term->id] = sprintf(
                                '<div class="rich-text p-16">%s</div>',
                                $variables['term']->description
                            );
                        } catch (SyntaxError $e) {
                            Craft::error($e->getMessage(), 'glossary');
                        }

                        return '{{%' . $token . '%}}';
                    });
                }
            }

            foreach ($replacements as $token => $replacement) {
                $text = s($text)->replace('{{%' . $token . '%}}', $replacement);
            }

            $renderedTerms = '';
            foreach ($this->usedTerms as $id => $usedTerm) {
                $renderedTerms .= Html::tag('div', $usedTerm, [
                    'id' => 'term-' . $id,
                ]);
            }

            $this->renderedTerms = Html::tag('div', $renderedTerms, [
                'id' => 'glossary-terms',
                'style' => 'display: none;',
            ]);

        } catch (Exception $e) {
            Craft::error('Error when rendering glossary terms: ' . $e->getMessage(), 'glossary');
            $text = $originalText;
        }

        return $text;
    }

    /**
     * Walks text nodes and emits a <button> per occurrence, paired with the
     * dialog in resources/src/index.js.
     *
     * @param string $text
     * @param Glossary $glossary
     *
     * @return string
     */
    protected function renderTermsAccessible(string $text, Glossary $glossary): string
    {
        $originalText = $text;

        try {
            $terms = Term::find()->glossary($glossary)->all();
            if (!$terms) {
                return $text;
            }

            // Longest first, so "learning sciences" wins over "learning".
            usort($terms, static fn(Term $a, Term $b) => mb_strlen($b->term) <=> mb_strlen($a->term));

            $text = $this->replaceInTextNodes($text, $terms);

            $renderedTerms = '';
            foreach ($this->usedTerms as $id => $usedTerm) {
                $renderedTerms .= Html::tag('div', $usedTerm, [
                    'id' => 'term-' . $id,
                ]);
            }

            $this->renderedTerms = Html::tag('div', $renderedTerms, [
                'id' => 'glossary-terms',
                'hidden' => true,
            ]);
        } catch (Exception $e) {
            Craft::error('Error when rendering glossary terms: ' . $e->getMessage(), 'glossary');
            $text = $originalText;
        }

        return $text;
    }

    /**
     * Walks the parsed document so only real text is touched — never markup,
     * attribute values or link interiors.
     *
     * @param string $html
     * @param Term[] $terms longest term first
     *
     * @return string
     */
    protected function replaceInTextNodes(string $html, array $terms): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $dom = new DOMDocument();

        // The XML declaration pins the encoding; libxml assumes Latin-1 and
        // mangles non-ASCII without it. The wrapper gives us one node to unwrap.
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="glossary-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            Craft::warning('Could not parse text for glossary replacement; leaving it untouched.', 'glossary');

            return $html;
        }

        $root = $dom->getElementById('glossary-root');
        if ($root === null) {
            return $html;
        }

        $xpath = new DOMXPath($dom);

        $textNodes = $xpath->query(
            './/text()[not(ancestor::a) and not(ancestor::button) and not(ancestor::script)'
            . ' and not(ancestor::style) and not(ancestor::textarea)'
            // A labelled button inside a heading overrides its accessible name.
            . ' and not(ancestor::h1) and not(ancestor::h2) and not(ancestor::h3)'
            . ' and not(ancestor::h4) and not(ancestor::h5) and not(ancestor::h6)'
            . ' and not(ancestor::*[@data-glossary-skip])]',
            $root
        );

        $changed = false;
        foreach ($textNodes as $node) {
            $changed = $this->replaceInTextNode($dom, $node, $terms) || $changed;
        }

        // Nothing matched, so skip re-serialising: libxml normalises as it writes.
        if (!$changed) {
            return $html;
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Replaces every term occurrence in one text node with a trigger element.
     *
     * @param DOMDocument $dom
     * @param DOMText     $node
     * @param Term[]      $terms
     *
     * @return bool whether anything was marked up
     */
    protected function replaceInTextNode(DOMDocument $dom, DOMText $node, array $terms): bool
    {
        $text = $node->nodeValue;
        if (trim($text) === '') {
            return false;
        }

        $pattern = $this->buildPattern($terms);
        if ($pattern === null || !preg_match($pattern, $text)) {
            return false;
        }

        $pieces = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($pieces === false) {
            return false;
        }

        $fragment = $dom->createDocumentFragment();

        foreach ($pieces as $piece) {
            $term = $this->matchTerm($piece, $terms);

            if ($term === null) {
                $fragment->appendChild($dom->createTextNode($piece));
                continue;
            }

            $this->rememberTerm($term);
            $fragment->appendChild($this->buildTrigger($dom, $piece, $term));
        }

        $node->parentNode->replaceChild($fragment, $node);

        return true;
    }

    /**
     * One alternation covering every term and synonym, each alternative
     * carrying its own case and word-boundary flags, so a text node is scanned
     * once rather than once per term.
     *
     * @param Term[] $terms
     *
     * @return string|null
     */
    protected function buildPattern(array $terms): ?string
    {
        $alternatives = [];

        foreach ($terms as $term) {
            foreach ($this->parseTerms($term) as $word) {
                $word = trim($word);
                if ($word === '') {
                    continue;
                }

                $quoted = preg_quote($word, '/');
                $body = $term->matchSubstring ? $quoted : '\b' . $quoted . '\b';
                $alternatives[] = $term->caseSensitive ? $body : '(?i:' . $body . ')';
            }
        }

        if (!$alternatives) {
            return null;
        }

        return '/(' . implode('|', $alternatives) . ')/u';
    }

    /**
     * Finds which term a matched piece of text belongs to.
     *
     * @param string $piece
     * @param Term[] $terms
     *
     * @return Term|null
     */
    protected function matchTerm(string $piece, array $terms): ?Term
    {
        foreach ($terms as $term) {
            foreach ($this->parseTerms($term) as $word) {
                $word = trim($word);
                if ($word === '') {
                    continue;
                }

                $same = $term->caseSensitive
                    ? $piece === $word
                    : mb_strtolower($piece) === mb_strtolower($word);

                if ($same) {
                    return $term;
                }

                if ($term->matchSubstring && mb_stripos($piece, $word) !== false) {
                    return $term;
                }
            }
        }

        return null;
    }

    /**
     * The in-text trigger: a button, not a span, so it is keyboard reachable
     * and announced as a control.
     *
     * @param DOMDocument $dom
     * @param string      $label the matched text, kept verbatim so casing is preserved
     * @param Term        $term
     *
     * @return DOMElement
     */
    protected function buildTrigger(DOMDocument $dom, string $label, Term $term): DOMElement
    {
        $button = $dom->createElement('button');
        $button->appendChild($dom->createTextNode($label));

        $button->setAttribute('type', 'button');
        $button->setAttribute('class', 'glossary');
        $button->setAttribute('data-glossary-term', 'term-' . $term->id);
        $button->setAttribute('aria-expanded', 'false');

        // $label, not $term->term: the accessible name must start with the
        // visible text (WCAG 2.5.3), which for a synonym match differs.
        $button->setAttribute('aria-label', $label . ' — show definition');

        return $button;
    }

    /**
     * Records a term's definition for the hidden definitions list. Accumulates
     * across calls, as the filter runs once per rich-text field.
     *
     * @param Term $term
     */
    protected function rememberTerm(Term $term): void
    {
        if (isset($this->usedTerms[$term->id])) {
            return;
        }

        $this->usedTerms[$term->id] = sprintf(
            '<div class="rich-text p-16">%s</div>',
            $term->description
        );
    }

    /**
     * Returns the rendered terms
     *
     * @return string
     */
    public function getRenderedTerms(): string
    {
        return $this->renderedTerms;
    }
}