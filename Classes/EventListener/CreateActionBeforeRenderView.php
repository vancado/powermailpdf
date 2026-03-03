<?php

namespace Undkonsorten\Powermailpdf\EventListener;

use FPDM;
use In2code\Powermail\Domain\Model\Answer;
use In2code\Powermail\Domain\Model\Field;
use In2code\Powermail\Domain\Model\Mail;
use TYPO3\CMS\Core\Error\Exception;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Fluid\View\StandaloneView;
use In2code\Powermail\Events\FormControllerCreateActionBeforeRenderViewEvent;

final class CreateActionBeforeRenderView
{
    protected ?string $encoding = null;
    protected array $settings = [];

    public function __construct(
        protected ResourceFactory $resourceFactory,
        private StandaloneView $standaloneView,
        private ConfigurationManagerInterface $configurationManager,
    ) {}

    public function __invoke(FormControllerCreateActionBeforeRenderViewEvent $event): void
    {
        $fullTypoScript = $this->configurationManager
            ->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);

        $this->settings = $fullTypoScript['plugin.']['tx_powermailpdf.']['settings.'] ?? [];

        if (empty($this->settings['enablePowermailPdf'])) {
            return;
        }

        if (!empty($this->settings['sourceFile'])) {
            if (!file_exists(GeneralUtility::getFileAbsFileName($this->settings['sourceFile']))) {
                throw new \Exception(
                    "The file does not exist: " . $this->settings['sourceFile'],
                    1417520887
                );
            }
        }

        $mail = $event->getMail();
        $formController = $event->getFormController();

        $powermailPdfFile = null;

        if (!empty($this->settings['fillPdf'])) {
            $powermailPdfFile = $this->generatePdf($mail);
        }

        if (!empty($this->settings['showDownloadLink']) && $powermailPdfFile) {
            $label = LocalizationUtility::translate("download", "powermailpdf");

            $answer = GeneralUtility::makeInstance(Answer::class);
            $field = GeneralUtility::makeInstance(Field::class);

            $field->setTitle(
                LocalizationUtility::translate('downloadLink', 'powermailpdf')
            );
            $field->setMarker('downloadLink');
            $field->setType('downloadLink');

            $answer->setField($field);
            $answer->setValue($this->render($powermailPdfFile, $label));

            $mail->addAnswer($answer);
        }

        if (!empty($this->settings['email.']['attachFile']) && $powermailPdfFile) {
            $settings = $formController->getSettings();

            $settings['receiver']['addAttachment']['value']
                = $powermailPdfFile->getForLocalProcessing(false);

            $settings['sender']['addAttachment']['value']
                = $powermailPdfFile->getForLocalProcessing(false);

            $formController->setSettings($settings);
        }
    }

    protected function generatePdf(Mail $mail): File
    {
        $this->encoding = $this->settings['encoding'] ?? null;

        /** @var Folder $folder */
        $folder = $this->resourceFactory
            ->getFolderObjectFromCombinedIdentifier($this->settings['target.']['pdf']);

        if (!class_exists('\FPDM')) {
            @include 'phar://' . ExtensionManagementUtility::extPath('powermailpdf')
                . 'Resources/Private/PHP/fpdm.phar/vendor/autoload.php';
        }

        $fieldMap = $this->settings['fieldMap.'] ?? [];
        $answers = $mail->getAnswers();

        $fdfDataStrings = [];

        foreach ($fieldMap as $fieldID => $fieldConfig) {

            $pdfFieldName = explode('.', $fieldID)[0];
            $fdfDataStrings[$pdfFieldName] = 'k.A.';

            if (is_array($fieldConfig)) {
                $pdfFieldType = $fieldConfig['type'] ?? 'text';
                $formFieldName = $fieldConfig['form_name'] ?? '';
                $formValue = $fieldConfig['form_value'] ?? null;
                $pdfValue = $fieldConfig['pdf_value'] ?? null;
            } else {
                $pdfFieldType = 'text';
                $formFieldName = $fieldConfig;
                $formValue = null;
                $pdfValue = null;
            }

            foreach ($answers as $answer) {

                if ($formFieldName !== $answer->getField()->getMarker()) {
                    continue;
                }

                $answerValue = $answer->getValue();

                if ($pdfFieldType === 'text') {
                    $fdfDataStrings[$pdfFieldName] = $this->encodeValue($answerValue);
                }

                elseif ($pdfFieldType === 'checkbox') {

                    if (is_array($answerValue)) {
                        if (in_array($formValue, $answerValue, true)) {
                            $fdfDataStrings[$pdfFieldName] =
                                $this->encodeValue($pdfValue);
                        }
                    } else {
                        if ($answerValue == $formValue) {
                            $fdfDataStrings[$pdfFieldName] =
                                $this->encodeValue($pdfValue);
                        }
                    }
                }
            }
        }

        if (!empty($this->settings['variables.'])) {
            $typoScriptService = GeneralUtility::makeInstance(TypoScriptService::class);
            $variables = $typoScriptService->convertTypoScriptArrayToPlainArray(
                $this->settings['variables.']
            );

            $cObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);

            foreach ($variables as $key => $item) {
                $type = $item['_typoScriptNodeValue'] ?? '';
                unset($item['_typoScriptNodeValue']);
                $fdfDataStrings[$key] = $cObject->cObjGetSingle($type, $item);
            }
        }

        $pdfOriginal = GeneralUtility::getFileAbsFileName($this->settings['sourceFile']);

        if (empty($pdfOriginal)) {
            throw new Exception(
                "No pdf file is set in TypoScript.",
                1417432239
            );
        }

        $pdfFlatTempFile = null;
        $info = pathinfo($pdfOriginal);
        $pdfFilename = basename($pdfOriginal, '.' . $info['extension']) . '_';
        $pdfTempFile = GeneralUtility::tempnam($pdfFilename, '.pdf');

        $pdf = new \FPDM($pdfOriginal);
        $pdf->Load($fdfDataStrings, !$this->encoding);
        $pdf->Merge();
        $pdf->Output("F", GeneralUtility::getFileAbsFileName($pdfTempFile));

        if (!empty($this->settings['flatten']) && !empty($this->settings['flattenTool'])) {

            $pdfFlatTempFile = GeneralUtility::tempnam($pdfFilename, '.pdf');
            $tempFile = GeneralUtility::tempnam($pdfFilename, '.pdf');

            switch ($this->settings['flattenTool']) {

                case 'gs':
                    @shell_exec(
                        "gs -sDEVICE=pdfwrite -dSubsetFonts=false -dPDFSETTINGS=/default "
                        . "-dNOPAUSE -dBATCH -sOutputFile="
                        . escapeshellarg($pdfFlatTempFile)
                        . " "
                        . escapeshellarg($pdfTempFile)
                    );
                    break;

                case 'pdftocairo':
                    @shell_exec(
                        'pdftocairo -pdf '
                        . escapeshellarg($pdfTempFile)
                        . ' '
                        . escapeshellarg($pdfFlatTempFile)
                    );
                    break;

                case 'pdftk':
                    @shell_exec(
                        'pdftk '
                        . escapeshellarg($pdfTempFile)
                        . ' generate_fdf output '
                        . escapeshellarg($tempFile)
                    );
                    @shell_exec(
                        'pdftk '
                        . escapeshellarg($pdfTempFile)
                        . ' fill_form '
                        . escapeshellarg($tempFile)
                        . ' output '
                        . escapeshellarg($pdfFlatTempFile)
                        . ' flatten'
                    );
                    break;
            }
        }

        if ($pdfFlatTempFile && file_exists($pdfFlatTempFile)) {
            return $folder->addFile($pdfFlatTempFile);
        }

        return $folder->addFile($pdfTempFile);
    }

    protected function render(File $file, string $label)
    {
        $templatePath = GeneralUtility::getFileAbsFileName($this->settings['template']);

        $this->standaloneView->setFormat('html');
        $this->standaloneView->setTemplatePathAndFilename($templatePath);
        $this->standaloneView->assignMultiple([
            'link' => $file->getPublicUrl(),
            'label' => $label
        ]);

        return $this->standaloneView->render();
    }

    protected function encodeValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }

        $value = (string)$value;

        if ($this->encoding) {
            return iconv('UTF-8', $this->encoding, $value);
        }

        return $value;
    }
}