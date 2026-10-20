<?php

namespace codemonauts\glossary\resources;

use craft\web\AssetBundle;

class GlossaryFrontend extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@codemonauts/glossary/resources';

        $this->js = [
            'js/Glossary.js',
        ];

        // light.css themes legacy mode, glossary.css accessible mode. Both
        // ship either way; each is inert in the other's mode.
        $this->css = [
            'css/tippy.css',
            'css/light.css',
            'css/glossary.css',
        ];

        parent::init();
    }
}
