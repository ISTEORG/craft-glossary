<?php

namespace codemonauts\glossary\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Accessible dialogs instead of hover tooltips. Off by default so an
     * upgrade changes nothing. Overridable from config/glossary.php.
     *
     * @var bool
     */
    public bool $accessiblePopups = false;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['accessiblePopups'], 'boolean'],
        ]);
    }
}
