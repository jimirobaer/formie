<?php
namespace verbb\formie\base;

use verbb\formie\base\Integration;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\events\SendIntegrationPayloadEvent;
use verbb\formie\models\EmailMarketingList;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;

abstract class EmailMarketing extends Integration implements IntegrationInterface
{
    // Constants
    // =========================================================================

    const EVENT_BEFORE_SEND_PAYLOAD = 'beforeSendPayload';
    const EVENT_AFTER_SEND_PAYLOAD = 'afterSendPayload';
    const CONNECT_SUCCESS = 'success';


    // Properties
    // =========================================================================

    protected $_client;


    // Static Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function isSelectable(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function supportsConnection(): bool
    {
        return true;
    }


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function getSettingsHtml(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getFormSettingsHtml(Form $form): string
    {
        return '';
    }

    /**
     * Returns the frontend HTML.
     *
     * @param Form $form
     * @return string
     */
    public function getFrontEndHtml(Form $form): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function hasValidSettings(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function checkConnection($useCache = true): bool
    {
        $cacheKey = 'formie-email-' . $this->handle . '-connection';
        $cache = Craft::$app->getCache();

        if ($useCache && $status = $cache->get($cacheKey)) {
            if ($status === self::CONNECT_SUCCESS) {
                return true;
            }
        }

        $success = $this->fetchConnection();

        if ($success) {
            Craft::$app->getCache()->set($cacheKey, self::CONNECT_SUCCESS);
        }

        return $success;
    }

    public function getConnectionStatus()
    {
        // Lack of translation is deliberate
        return $this->checkConnection() ? 'Connected' : 'Not connected';
    }

    /**
     * @inheritDoc
     */
    public function getAllLists($useCache = true)
    {
        $cacheKey = 'formie-email-' . $this->handle . '-lists';
        $cache = Craft::$app->getCache();

        if ($useCache && $lists = $cache->get($cacheKey)) {
            return $lists;
        }

        $lists = $this->fetchLists();

        $cache->set($cacheKey, $lists);

        return $lists;
    }

    /**
     * @inheritDoc
     */
    public function getListFields($listId = null)
    {
        $fields = [];

        if (!$listId) {
            $listId = $this->listId;
        }

        $list = $this->getListById($listId);

        foreach ($list->fields as $listField) {
            $fields[] = [
                'name' => $listField->name,
                'handle' => $listField->tag,
                'required' => $listField->required,
            ];
        }

        return $fields;
    }

    /**
     * @inheritDoc
     */
    public function validateFieldMapping($attribute)
    {
        if ($this->enabled) {
            // Ensure we check against any required fields
            $list = $this->getListById($this->listId);

            foreach ($list->fields as $field) {
                $value = $this->fieldMapping[$field->tag] ?? '';

                if ($field->required && $value === '') {
                    $this->addError($attribute, Craft::t('formie', '{name} must be mapped.', ['name' => $field->name]));
                    return;
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['listId'], 'required'];
        $rules[] = [['fieldMapping'], 'validateFieldMapping'];

        return $rules;
    }

    /**
     * @inheritDoc
     */
    public function getElementFieldsFromRequest($request)
    {
        $listId = $request->getParam('listId');

        if (!$listId) {
            return ['error' => Craft::t('formie', 'No “{listId}” provided.')];
        }

        return $this->getListFields($listId);
    }

    /**
     * @inheritDoc
     */
    public function getListOptions($useCache = true): array
    {
        $options = [
            ['label' => Craft::t('formie', 'Select an option'), 'value' => ''],
        ];

        $lists = $this->getAllLists($useCache);

        foreach ($lists as $list) {
             $options[] = ['label' => $list->name, 'value' => $list->id];
        }

        return $options;
    }


    // Protected Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    protected function getListById($listId)
    {
        $lists = $this->getAllLists();

        foreach ($lists as $list) {
            if ($list->id === $listId) {
                return $list;
            }
        }

        return new EmailMarketingList();
    }

    /**
     * @inheritDoc
     */
    protected function getFieldMappingValues(Submission $submission)
    {
        $fieldValues = [];

        foreach ($this->fieldMapping as $tag => $formFieldHandle) {
            if ($formFieldHandle) {
                $formFieldHandle = str_replace(['{', '}'], ['', ''], $formFieldHandle);

                // Convert to string. We'll introduce more complex field handling in the future, but this will
                // be controlled at the integration-level. Some providers might only handle an address as a string
                // others might accept an array of content. The integration should handle this...
                $fieldValues[$tag] = (string)$submission->getFieldValue($formFieldHandle);
            }
        }

        return $fieldValues;
    }

    /**
     * @inheritDoc
     */
    protected function beforeSendPayload(Submission $submission)
    {
        $event = new SendIntegrationPayloadEvent([
            'submission' => $submission,
            'integration' => $this,
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND_PAYLOAD, $event);

        if (!$event->isValid) {
            Integration::log($this, 'Sending payload cancelled by event hook.');
        }

        return $event->isValid;
    }

    /**
     * @inheritDoc
     */
    protected function afterSendPayload(Submission $submission, $response)
    {
        $event = new SendIntegrationPayloadEvent([
            'submission' => $submission,
            'response' => $response,
            'integration' => $this,
        ]);
        $this->trigger(self::EVENT_AFTER_SEND_PAYLOAD, $event);

        if (!$event->isValid) {
            Integration::log($this, 'Payload marked as invalid by event hook.');
        }

        return $event->isValid;
    }
}
