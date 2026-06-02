<?php

namespace Da\export;

use Da\export\options\OptionInterface;
use Da\export\options\CsvOption;
use Da\export\options\XlsxOption;
use Da\export\options\OdsOption;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Widget;
use yii\helpers\Url;

class ExportMenu extends Widget
{
    /**
     * Export formats
     */
    public const FORMAT_CSV = 'CSV';
    public const FORMAT_ODS = 'ODS';
    public const FORMAT_EXCEL_X = 'Excel2007';

    /**
     * Targets available
     */
    public const TARGET_SELF = '_self';
    public const TARGET_BLANK = '_blank';

    public const TARGET_QUEUE = 'queue';

    /**
     * @see OptionAbstract
     * @var var used in {@link OptionAbstract} class to create the report
     */
    public $dataProvider;

    /**
     * @see OptionAbstract
     * @var var used in {@link OptionAbstract} class to create the columns report
     */
    public $columns;

    /**
     * @see OptionAbstract
     * @var var used in {@link OptionAbstract} class to create the report
     */
    public $filename;

    /**
     * @see OptionAbstract
     * @var var used in {@link OptionAbstract} to check whether export the footer or not
     */
    public $exportFooter;

    /**
     * @var array the export menu wrapper div options.
     * ~~~php
     * [
     *      'class' => 'btn-group',
     * ];
     * ~~~
     */
    public $options;

    /**
     * @var array the button AND dropDown export menu options.
     * ~~~php
     * [
     *      'class' => 'btn btn-default',
     *      'label' => 'Export',
     *      'menuOptions' => [
     *          'class' => 'dropdown-menu dropdown-menu-right'
     *      ]
     * ];
     * ~~~
     */
    public $dropDownOptions = [
        'class' => 'btn btn-default'
    ];

    /**
     * @var array the drop down items.
     * ~~~php
     * [
     *    'label' => Yii::t('export', 'Excel 95 +'),
     *    'icon' => 'file-excel-o',
     *    'options' => ['title' => Yii::t('export', 'Microsoft Excel 95+ (xls)')],
     *    'alertMsg' => Yii::t('export', 'The EXCEL 95+ (xls) export file will be generated for download.'),
     *    'extension' => 'xls',
     * ];
     * ~~~
     */
    public $dropDownItems;

    /**
     * @var POST param read to identify when the user choose the option to download
     */
    public $exportRequestParam;

    /**
     * @var bool var to set whether the widget should render or download the report
     */
    protected $selectedOption = false;

    /**
     * @see OptionAbstract
     * @var string how the page will delivery the report
     */
    public $target;

    /**
     * @var string timezone for dates
     */
    public $timeZone;

    /**
     * @var array queue configuration array
     *
     * ~~~php
     * [
     *      'queueName' => 'test',
     *      'queueAdapter' => '\Da\export\queue\beanstalkd\BeanstalkdQueueStoreAdapter',
     *      'queueMessage' => function () {
     *          return "hi";
     *      }
     * ~~~
     */
    public $queueConfig;

    /**
     * @todo
     */
    public $showConfirmAlert;

    /**
     * @inheritdoc
     */
    public function init()
    {
        if (empty($this->exportRequestParam)) {
            if (!isset($this->options['id'])) {
                $this->options['id'] = $this->getId();
            }
            $this->exportRequestParam = 'exportFull_' . $this->options['id'];
        }

        if (empty($this->target)) {
            $this->target = static::TARGET_SELF;
        }

        $this->setSelectedOption();
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        if ($this->target == static::TARGET_QUEUE) {
            $this->dispatchQueue();
        }

        if (!empty($this->selectedOption)) {
            $this->triggerDownload();
        }

        $this->renderForm();
    }

    protected function dispatchQueue()
    {
        if (!array_key_exists('queueName', $this->queueConfig)) {
            throw new InvalidConfigException("Your queueConfig param needs to have 'queueName' index.");
        }

        if (!array_key_exists('queueAdapter', $this->queueConfig)) {
            throw new InvalidConfigException("Your queueConfig param needs to have 'queueAdapter' index.");
        }

        if (!array_key_exists('queueMessage', $this->queueConfig)) {
            throw new InvalidConfigException("Your queueConfig param needs to have 'queueMessage' index.");
        }

        $queueAdapter = $this->queueConfig['queueAdapter'];

        if (!is_subclass_of($queueAdapter, '\Da\export\queue\QueueStoreAdapterInterface')) {
            throw new InvalidConfigException("Your queue adapter must implement QueueStoreAdapterInterface.");
        }

        /** @var QueueStoreAdapterInterface $queueInstance */
        $queueInstance = new $queueAdapter([
            'queueName' => $this->queueConfig['queueName'],
        ]);

        $queueMessage = $this->queueConfig['queueMessage'];
        if (is_callable($queueMessage)) {
            $queueMessage = call_user_func($queueMessage);
        }

        $queueInstance->enqueue($queueMessage);
    }

    /**
     * Set whether is to download or not
     */
    public function setSelectedOption()
    {
        $this->selectedOption = Yii::$app->request->post($this->exportRequestParam);
    }

    /**
     * Perform the report download
     */
    public function triggerDownload()
    {
        $items = $this->parseDropDownItems();

        if (!array_key_exists($this->selectedOption, $items)) {
            throw new InvalidConfigException('The selected item is not configured.');
        }

        $selectedItem = $items[$this->selectedOption];
        $className = $selectedItem['className'];

        if (empty($className) && !($className instanceof OptionInterface)) {
            throw new InvalidConfigException('The selected item does not have a valid class.');
        }

        $options = $this->parseDownloadOptions();

        /** @var OptionInterface $instance */
        $instance = new $className($options);

        $this->clearBuffers();
        $instance->process();
        exit;
    }

    /**
     * Remove previous HTML rendered
     */
    protected function clearBuffers()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * Render the export menu form
     */
    protected function renderForm()
    {
        $options = $this->parseOptions();
        $formOptions = $this->parseFormOptions();
        $script = $this->getScript($options['id']);

        $view = $this->getView();
        ExportMenuAsset::register($view);
        $view->registerJs($script);

        $dropDownOptions = $this->parseDropDownOptions();
        $buttonOptions = $this->parseButtonOptions();

        echo $this->render('form', [
            'options' => $options,
            'formOptions' => $formOptions,
            'dropDownOptions' => $dropDownOptions,
            'buttonOptions' => $buttonOptions,
            'exportRequestParam' => $this->exportRequestParam,
        ]);
    }

    /**
     * javascript code
     *
     * @param $id
     * @return string
     */
    protected function getScript($id)
    {
        $exportRequestParam = $this->exportRequestParam;

        return <<<SCRIPT
jQuery('#{$id}').exportMenu({
    'formId': 'form-{$id}',
    'exportRequestParam': '{$exportRequestParam}'
});
SCRIPT;
    }

    /**
     * treat all options in the DIV related array
     *
     * @return array
     */
    protected function parseOptions()
    {
        $options = $this->options;

        if (empty($options['class'])) {
            $options['class'] = 'btn-group';
        }

        if (empty($options['id'])) {
            $options['id'] = $this->getId();
        }

        return $options;
    }

    /**
     * treat all options in the FORM related array
     *
     * @return array
     */
    protected function parseFormOptions()
    {
        $options = $this->parseOptions();

        $target = $this->target;
        if ($target == static::TARGET_QUEUE) {
            $target = '_self';
        }

        return [
            'id' => 'form-' . $options['id'],
            'action' => Url::current(),
            'method' => 'POST',
            'options' => [
                'target' => $target,
            ]
        ];
    }

    /**
     * treat all options in the drop down related array
     *
     * @return array
     */
    protected function parseDropDownOptions()
    {
        $dropDownOptions = [];
        $dropDownOptions['options'] = !empty($this->dropDownOptions['menuOptions']) ? $this->dropDownOptions['menuOptions'] : [];
        $dropDownOptions['items'] = $this->parseDropDownItems();

        return $dropDownOptions;
    }

    /**
     * treat all options in the button related array
     *
     * @return array
     */
    protected function parseButtonOptions()
    {
        $buttonOptions = $this->dropDownOptions;
        unset($buttonOptions['menuOptions']);

        if (empty($buttonOptions['class'])) {
            $buttonOptions['class'] = 'btn btn-default dropdown-toggle';
        } else {
            $buttonOptions['class'] .= ' dropdown-toggle';
        }

        if (empty($buttonOptions['title'])) {
            $buttonOptions['title'] = "Export data in selected format";
        }

        if (empty($buttonOptions['data-toggle'])) {
            $buttonOptions['data-toggle'] = "dropdown";
        }

        if (empty($buttonOptions['aria-expanded'])) {
            $buttonOptions['aria-expanded'] = "false";
        }

        return $buttonOptions;
    }

    /**
     * generate default formats if the dropDownItems options is empty
     *
     * @return array
     */
    protected function parseDropDownItems()
    {
        if (empty($this->dropDownItems)) {
            return [
                self::FORMAT_CSV => [
                    'label' => 'CSV',
                    'options' => [
                        'title' => 'Comma Separated Values',
                        'data-id' => self::FORMAT_CSV,
                    ],
                    'url' => 'javascript:;',
                    'className' => CsvOption::class,
                ],
                self::FORMAT_ODS => [
                    'label' => 'ODS',
                    'options' => [
                        'title' => 'OpenDocument Spreadsheet (ods)',
                        'data-id' => self::FORMAT_ODS,
                    ],
                    'url' => 'javascript:;',
                    'className' => OdsOption::class,
                ],
                self::FORMAT_EXCEL_X => [
                    'label' => 'Excel 2007+',
                    'options' => [
                        'title' => 'Microsoft Excel 2007+ (xlsx)',
                        'data-id' => self::FORMAT_EXCEL_X,
                    ],
                    'url' => 'javascript:;',
                    'className' => XlsxOption::class,
                ],
            ];
        }
        return $this->dropDownItems;
    }

    /**
     * @see OptionAbstract
     * generate array to {@link OptionAbstract} class
     *
     * @return array
     */
    protected function parseDownloadOptions()
    {
        return [
            'filename' => $this->filename,
            'dataProvider' => $this->dataProvider,
            'columns' => $this->columns,
            'exportFooter' => $this->exportFooter,
            'target' => $this->target,
            'timeZone' => $this->timeZone,
        ];
    }
}
