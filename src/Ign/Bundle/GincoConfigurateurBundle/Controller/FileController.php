<?php
namespace Ign\Bundle\GincoConfigurateurBundle\Controller;

use Ign\Bundle\ConfigurateurBundle\Entity\TableFormat;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ign\Bundle\ConfigurateurBundle\Entity\FileFormat;
use Ign\Bundle\ConfigurateurBundle\Entity\DataRepository;
use Ign\Bundle\ConfigurateurBundle\Entity\Dataset;
use Ign\Bundle\ConfigurateurBundle\Entity\Format;
use Ign\Bundle\ConfigurateurBundle\Entity\FileField;
use Ign\Bundle\ConfigurateurBundle\Entity\Field;
use Ign\Bundle\ConfigurateurBundle\Entity\Data;
use Ign\Bundle\ConfigurateurBundle\Form\FileFormatType;
use Ign\Bundle\ConfigurateurBundle\Form\FileFieldAutoType;
use Ign\Bundle\ConfigurateurBundle\Form\DatasetImportType;
use Ign\Bundle\ConfigurateurBundle\Form\FieldMappingAutoType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Assetic\Exception\Exception;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Ign\Bundle\ConfigurateurBundle\Controller\FileController as FileControllerBase;

class FileController extends FileControllerBase {

	/**
	 *
	 * @param
	 *        	$datasetId
	 * @param
	 *        	$fileFormat
	 * @param Request $request
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse @Route("/datasetsimport/{datasetId}/files/{fileFormat}/autofields/", name="configurateur_file_auto_add_fields")
	 *
	 *         Automatically adds fields to the file (same fields as the ones in the chosen table)
	 */
	public function autoAction($datasetId, $fileFormat, Request $request) {
		$em = $this->getDoctrine()->getManager();

		$dataset = $em->getRepository('IgnConfigurateurBundle:Dataset')->find($datasetId);

		// Create Auto-Add-Fieldform
		$formOptions = array(
			'modelId' => $dataset->getModel()->getId(),
			'action' => $this->generateUrl('configurateur_file_fields', array(
				'datasetId' => $datasetId,
				'format' => $fileFormat
			))
		);
		$autoAddFieldsForm = $this->createForm(FileFieldAutoType::class, null, $formOptions);
		$autoAddFieldsForm->handleRequest($request);

		if ($autoAddFieldsForm->isValid()) {
			$tableFormat = $autoAddFieldsForm->get('table_format')
				->getData()
				->getFormat();
			$table = $em->getRepository('IgnConfigurateurBundle:TableFormat')->find($tableFormat);
			$tableFields = $em->getRepository('IgnConfigurateurBundle:TableField')->findFieldsByTableFormat($tableFormat);
			// var_dump( $tableFields);

			// Get mandatory fields in table fields
			$isMandatory = function ($field) {
				return ($field['isMandatory'] == 1);
			};
			$mandatoryFields = array_filter($tableFields, $isMandatory);

			$fileFields = $em->getRepository('IgnConfigurateurBundle:FileField')->findFieldsByFileFormat($fileFormat);
			// var_dump($fileFields);

			// Add only mandatory fields ?
			$mandatoryOnly = $autoAddFieldsForm->get('only_mandatory')->getData();
			if ($mandatoryOnly) {
				$tableFields = $mandatoryFields;
			}

			$tableDatas = array_column($tableFields, 'fieldName');

			// Add calculated fields ?
			$calculatedIncluded = $autoAddFieldsForm->get('with_calculated')->getData();
			$calculatedFields = array();
			if (!$calculatedIncluded) {
				$possibleCalculatedFields = array(
					'sensible',
					'sensiniveau',
					'sensidateattribution',
					'sensireferentiel',
					'sensiversionreferentiel',
					'deedatedernieremodification',
					'sensimanuelle',
					'sensialerte',
					'codecommunecalcule',
					'codedepartementcalcule',
					'codemaillecalcule',
					'nomcommunecalcule',
					'nomvalide'
				);
				$calculatedFields = array_intersect($possibleCalculatedFields, $tableDatas);
				$tableDatas = array_diff($tableDatas, $calculatedFields);
				// var_dump($tableDatas);
			}

			// remove technical fields
			$technicalFields = array(
				'PROVIDER_ID',
				'SUBMISSION_ID'
			);
			$technicalFields = array_merge($technicalFields, explode(',', $table->getPrimaryKey()));

			$tableDatas = array_diff($tableDatas, $technicalFields);

			$calculatedFieldsLabels = array();
			foreach ($calculatedFields as $data) {
				$calculatedFieldsLabels[] = $em->getRepository('IgnConfigurateurBundle:Data')
					->find($data)
					->getLabel();
			}

			// Generate a report
			$report = array(
				'fileLabel' => $em->getRepository('IgnConfigurateurBundle:FileFormat')
					->find($fileFormat)
					->getLabel(),
				'tableLabel' => $em->getRepository('IgnConfigurateurBundle:TableFormat')
					->find($tableFormat)
					->getLabel(),
				'mandatoryOnly' => $mandatoryOnly,
				'calculatedFields' => $calculatedFieldsLabels
			);

			// Find table fields not already added as file fields
			$fileDatas = array_column($fileFields, 'fieldName');
			$fieldsToAdd = array_diff($tableDatas, $fileDatas);

			$fieldsToAddLabels = array();
			foreach ($fieldsToAdd as $data) {
				$fieldsToAddLabels[] = $em->getRepository('IgnConfigurateurBundle:Data')
					->find($data)
					->getLabel();
			}

			$report = array_merge($report, array(
				'tableFields' => $tableDatas,
				'fileFields' => $fileDatas,
				'fieldsToAdd' => $fieldsToAddLabels
			));

			// Add them ; get redirection in a variable
			$redirectResponse = $this->forward('IgnConfigurateurBundle:FileField:addFields', array(
				'datasetId' => $datasetId,
				'format' => $fileFormat,
				'addedFields' => implode(',', $fieldsToAdd)
			));

			// Update as mandatory in file the fields which are mandatory in table
			$mFields = array_column($mandatoryFields, 'fieldName');
			$mFields = array_intersect($fieldsToAdd, $mFields);

			foreach ($mFields as $mfield) {
				$fileField = $em->getRepository('IgnConfigurateurBundle:FileField')->findOneBy(array(
					'data' => $mfield,
					'fileFormat' => $fileFormat
				));
				$fileField->setIsMandatory('1');
			}
			$em->flush();

			$link = $this->generateUrl("configurateur_field_automapping_direct", array(
				'datasetId' => $datasetId,
				'fileFormat' => $fileFormat,
				'tableFormat' => $tableFormat
			));

			$report['mappingLink'] = $link;

			$notice = $this->generateReportAutoAddFields($report);

			$this->addFlash('notice-autoaddfields', $notice);
		} else {
			$this->addFlash('error-autoaddfields', $this->get('translator')
				->trans('fileField.auto.chooseatable'));
		}

		return $this->redirectToRoute('configurateur_file_fields', array(
			'datasetId' => $datasetId,
			'format' => $fileFormat
		));
	}

	/**
	 * Generates text report message, based on report table
	 * for auto-mapping action.
	 *
	 * @param
	 *        	$report
	 * @return string
	 */
	public function generateReportAutoAddFields($report) {
		$translator = $this->get('translator');

		if ($report['mandatoryOnly']) {
			$reportMessage = $translator->trans('fileField.auto.report.2', array(
				'%fileLabel%' => $report['fileLabel'],
				'%tableName%' => $report['tableLabel']
			));
			$reportMessage .= $translator->trans('fileField.auto.report.4', array(
				'%fileFields%' => count($report['fileFields']),
				'%tableFields%' => count($report['tableFields']) + count($report['calculatedFields'])
			));
		} else {
			$reportMessage = $translator->trans('fileField.auto.report.1', array(
				'%fileLabel%' => $report['fileLabel'],
				'%tableName%' => $report['tableLabel']
			));
			$reportMessage .= $translator->trans('fileField.auto.report.3', array(
				'%fileFields%' => count($report['fileFields']),
				'%tableFields%' => count($report['tableFields']) + count($report['calculatedFields'])
			));
		}
		if (count($report['fieldsToAdd']) == 0) {
			$reportMessage .= $translator->trans('fileField.auto.report.5');
		} else {
			$reportMessage .= $translator->trans('fileField.auto.report.6', array(
				'%fieldsAddedCount%' => count($report['fieldsToAdd']),
				'%fieldsAdded%' => implode(', ', $report['fieldsToAdd'])
			));
		}
		if ($report['calculatedFields'] != null && count($report['fieldsToAdd']) != 0) {
			$reportMessage .= $translator->trans('fileField.auto.report.8', array(
				'%calculatedFields%' => implode(', ', $report['calculatedFields']),
				'%calculatedFieldsCount%' => count($report['calculatedFields'])
			));
		}
		if ($report['fieldsToAdd'] > 0) {
			$reportMessage .= $translator->trans('fileField.auto.report.7', array(
				'%mappingLink%' => $report['mappingLink']
			));
		}
		return $reportMessage;
	}
}
