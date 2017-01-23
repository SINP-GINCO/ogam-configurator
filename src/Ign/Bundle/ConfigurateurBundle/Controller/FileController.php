<?php
namespace Ign\Bundle\ConfigurateurBundle\Controller;

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

class FileController extends Controller {

	/**
	 * @Route("datasetsimport/{id}/files/new/", name="configurateur_file_index", defaults={"id":0})
	 */
	public function newAction(Dataset $dataset, Request $request) {
		$em = $this->getDoctrine()->getManager();

		$file = new FileFormat();

		// Create form to add file
		$formFile = $this->createForm(FileFormatType::class, $file);
		// Add a "save and manage fields" button
		$formFile->add('saveAndFields', SubmitType::class, array(
			'label' => 'table.edit.saveandfields'
		));

		$formFile->handleRequest($request);

		if ($formFile->isValid()) {
			// Add/modif file form
			$file->setFormat(uniqid('file_'));
			$file->setFileExtension('CSV');
			$fileFormat = $file->getFormat();
			$file->setFileType($file->getlabel());

			$order = count($dataset->getFiles()) + 1;
			$file->setPosition((string) $order);

			// create a new Format
			$format = new Format();
			$format->setFormat($fileFormat);
			$format->setType('FILE');

			// Save file_format in the meta-class FORMAT
			$em->persist($format);
			$em->flush();

			// Save file in FILE_FORMAT
			$em->persist($file);
			$dataset->addFile($file);
			$em->flush();

			$nextAction = $formFile->get('saveAndFields')->isClicked() ? 'configurateur_file_fields' : 'configurateur_file_edit';

			return $this->redirectToRoute($nextAction, array(
				'datasetId' => $dataset->getId(),
				'format' => $fileFormat
			));
		}

		$files = $dataset->getFiles();
		return $this->render('IgnConfigurateurBundle:FileFormat:new.html.twig', array(
			'fileForm' => $formFile->createView(),
			'dataset' => $dataset
		));
	}

	/**
	 * Methode d'édition d'une File.
	 * @Route("datasetsimport/{datasetId}/files/{format}/edit/", name="configurateur_file_edit")
	 */
	public function editAction($datasetId, $format, Request $request) {
		$em = $this->getDoctrine()->getManager();
		$file = $em->getRepository('IgnConfigurateurBundle:FileFormat')->find($format);
		$dataset = $em->getRepository('IgnConfigurateurBundle:Dataset')->find($datasetId);

		if (!$file) {
			$errMsg = "Aucun FICHIER ne correspond à : " . $format;
			throw $this->createNotFoundException($errMsg);
		}

		// Create file definition form
		$form = $this->createForm(FileFormatType::class, $file);

		$form->handleRequest($request);

		if ($form->isValid()) {
			try {
				$em->flush();
				$this->addFlash('notice', $this->get('translator')
					->trans('file.edit.definition.success', array(
					'%file.label%' => $file->getLabel()
				)));
			} catch (\Doctrine\DBAL\DBALException $e) {
				$this->addFlash('notice', $this->get('translator')
					->trans('file.edit.definition.fail', array(
					'%file.label%' => $file->getLabel()
				)));
			}
		}

		return $this->render('IgnConfigurateurBundle:FileFormat:edit.html.twig', array(
			'fileForm' => $form->createView(),
			'file' => $file,
			'dataset' => $dataset
		));
	}

	/**
	 * Edition des champs du fichier.
	 * @Route("datasetsimport/{datasetId}/files/{format}/fields/", name="configurateur_file_fields")
	 */
	public function manageFieldsAction($datasetId, $format) {
		$em = $this->getDoctrine()->getManager();
		$file = $em->getRepository('IgnConfigurateurBundle:FileFormat')->find($format);
		$dataset = $em->getRepository('IgnConfigurateurBundle:Dataset')->find($datasetId);
		if (!$file) {
			$errMsg = "Aucun FICHIER ne correspond à : " . $format;
			throw $this->createNotFoundException($errMsg);
		}

		// Get dictionary
		$dataRepository = $em->getRepository('IgnConfigurateurBundle:Data');
		$allFields = $dataRepository->findAllFields();
		// Get File Fields
		$fileFieldRepository = $em->getRepository('IgnConfigurateurBundle:FileField');
		$fileFields = $fileFieldRepository->findFieldsByFileFormat($format);
		// Get mappings to get the non-calculated mandatory fields that can't be modified
		$mappings = $em->getRepository('IgnConfigurateurBundle:FieldMapping')->findMappings($format, 'FILE');
		$tableFieldRepository = $em->getRepository('IgnConfigurateurBundle:TableField');
		$mandatoryFields = array();
		foreach ($mappings as $mapping) {
			$tableField = $tableFieldRepository->find(array(
				'data' => $mapping['dstData'],
				'tableFormat' => $mapping['dstFormat']
			));
			if ($tableField->getIsMandatory() == '1' && $tableField->getIsCalculated() == '0') {
				$mandatoryFields[] = $mapping['dstData'];
			}
		}
		// "Auto Add Fields" Form
		$autoAddFieldsForm = $this->autoAddFieldsForm($dataset, $file);

		return $this->render('IgnConfigurateurBundle:FileFormat:fields.html.twig', array(
			'file' => $file,
			'dataset' => $dataset,
			'allFields' => $allFields,
			'fileFields' => $fileFields,
			'mandatoryFields' => $mandatoryFields,
			'autoAddFieldsForm' => $autoAddFieldsForm->createView()
		));
	}

	/**
	 * Returns the auto add fields form
	 *
	 * @param Dataset $dataset
	 * @param FileFormat $file
	 * @return array : the parameters array
	 */
	public function autoAddFieldsForm(Dataset $dataset, FileFormat $file) {
		$formOptions = array(
			'modelId' => $dataset->getModel()->getId(),
			'action' => $this->generateUrl('configurateur_file_auto_add_fields', array(
				'datasetId' => $dataset->getId(),
				'fileFormat' => $file->getFormat()
			))
		);
		$autoAddFieldsForm = $this->createForm(FileFieldAutoType::class, null, $formOptions);

		return $autoAddFieldsForm;
	}

	/**
	 * Deletes the file given by its id in the dataset given by its id.
	 * It will also delete all the FileField and Field (technical and non-technical) linked to the FileFormat,
	 * and also the Format. Redirects to file edition page.
	 * @Route("/datasetsimport/{datasetId}/files/{fileFormat}/delete/", name="configurateur_file_delete")
	 * @Template()
	 */
	public function deleteAction($datasetId, $fileFormat) {
		$em = $this->getDoctrine()->getManager();

		$formatRepository = $em->getRepository('IgnConfigurateurBundle:Format');
		$fileFormatRepository = $em->getRepository('IgnConfigurateurBundle:FileFormat');
		$datasetRepository = $em->getRepository('IgnConfigurateurBundle:Dataset');
		$fileFieldRepository = $em->getRepository('IgnConfigurateurBundle:FileField');
		$fieldRepository = $em->getRepository('IgnConfigurateurBundle:Field');
		$mappingRepository = $em->getRepository("IgnConfigurateurBundle:FieldMapping");

		$dataset = $datasetRepository->find($datasetId);
		$format = $formatRepository->find($fileFormat);
		$file = $fileFormatRepository->find($fileFormat);

		$fileName = $file->getLabel();
		$position = $file->getPosition();

		$mappingRepository->removeAllByFileFormat($format);
		$fileFieldRepository->deleteAllByFileFormat($format);
		$fieldRepository->deleteAllByFileFormat($format);
		$dataset->removeFile($file);
		$em->flush();

		$em->remove($file);
		$em->remove($format);

		// Update the position of each file left
		foreach ($dataset->getFiles() as $file) {
			if ($file->getPosition() > $position) {
				$file->setPosition($file->getPosition() - 1);
				$em->merge($file);
			}
		}

		$em->merge($dataset);

		try {
			$em->flush();
			$this->addFlash('notice', $this->get('translator')
				->trans('file.delete.success', array(
				'%fileName%' => $fileName
			)));
		} catch (PDOException $e) {
			$this->addFlash('error', $this->get('translator')
				->trans('file.delete.error', array(
				'%fileName%' => $fileName
			)));
		}

		return $this->redirectToRoute('configurateur_dataset_import_edit', array(
			'id' => $datasetId
		));
	}

	/**
	 * Methode de visualisation d'une File.
	 * @Route("datasetsimport/{datasetId}/files/{format}/view/", name="configurateur_file_view")
	 * @Template()
	 */
	public function viewAction($datasetId, $format) {
		$em = $this->getDoctrine()->getManager();

		$file = $em->getRepository('IgnConfigurateurBundle:FileFormat')->find($format);
		$dataset = $em->getRepository('IgnConfigurateurBundle:Dataset')->find($datasetId);

		if (!$file) {
			$errMsg = "Aucun FICHIER ne correspond à : " . $format;
			throw $this->createNotFoundException($errMsg);
		}

		// Get File Fields
		$fileFieldRepository = $em->getRepository('IgnConfigurateurBundle:FileField');
		$fileFields = $fileFieldRepository->findFieldsByFileFormat($file->getFormat());

		$fieldMappings = $em->getRepository('IgnConfigurateurBundle:FieldMapping')->findMappings($format, 'FILE');
		// Rewrite mappings on "OGAM_ID_..." (keys)
		foreach ($fieldMappings as $index => $fieldMapping) {
			if (strpos($fieldMapping['dstData'], TableFormat::PK_PREFIX) === 0) {
				$format = substr($fieldMapping['dstData'], strlen(TableFormat::PK_PREFIX));
				$tableFormat = $em->getRepository('IgnConfigurateurBundle:TableFormat')->find($format);
				$tableLabel = $tableFormat->getLabel();
				// Primary key or foreign key ?
				if ($format == $fieldMapping['dstFormat'])
					$fieldMappings[$index]['dstDataLabel'] = $this->get('Translator')->trans('data.primary_key', array(
						'%tableLabel%' => $tableLabel
					));
				else
					$fieldMappings[$index]['dstDataLabel'] = $this->get('Translator')->trans('data.foreign_key', array(
						'%tableLabel%' => $tableLabel
					));
			}
		}

		return $this->render('IgnConfigurateurBundle:FileFormat:view.html.twig', array(
			'file' => $file,
			'fileFields' => $fileFields,
			'datasetId' => $datasetId,
			'datasetLabel' => $dataset->getLabel(),
			'fieldMapping' => $fieldMappings
		));
	}

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

			// Get mandatory fields in table fields
			$isMandatory = function ($field) {
				return ($field['isMandatory'] == 1);
			};
			$mandatoryFields = array_filter($tableFields, $isMandatory);

			$fileFields = $em->getRepository('IgnConfigurateurBundle:FileField')->findFieldsByFileFormat($fileFormat);

			// Add only mandatory fields ?
			$mandatoryOnly = $autoAddFieldsForm->get('only_mandatory')->getData();
			if ($mandatoryOnly) {
				$tableFields = $mandatoryFields;
			}

			// Generate a report
			$report = array(
				'fileLabel' => $em->getRepository('IgnConfigurateurBundle:FileFormat')
					->find($fileFormat)
					->getLabel(),
				'tableLabel' => $em->getRepository('IgnConfigurateurBundle:TableFormat')
					->find($tableFormat)
					->getLabel(),
				'mandatoryOnly' => $mandatoryOnly
			)
			;

			// Find table fields not already added as file fields
			$tableDatas = array_column($tableFields, 'fieldName');

			// remove technical fields
			$technicalFields = array(
				'PROVIDER_ID',
				'SUBMISSION_ID'
			);
			$technicalFields = array_merge($technicalFields, explode(',', $table->getPrimaryKey()));

			$tableDatas = array_diff($tableDatas, $technicalFields);

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
				'%tableFields%' => count($report['tableFields'])
			));
		} else {
			$reportMessage = $translator->trans('fileField.auto.report.1', array(
				'%fileLabel%' => $report['fileLabel'],
				'%tableName%' => $report['tableLabel']
			));
			$reportMessage .= $translator->trans('fileField.auto.report.3', array(
				'%fileFields%' => count($report['fileFields']),
				'%tableFields%' => count($report['tableFields'])
			));
		}
		if (count($report['fieldsToAdd']) == 0) {
			$reportMessage .= $translator->trans('fileField.auto.report.5');
		} else {
			$reportMessage .= $translator->trans('fileField.auto.report.6', array(
				'%fieldsAddedCount%' => count($report['fieldsToAdd']),
				'%fieldsAdded%' => implode(', ', $report['fieldsToAdd'])
			));
			$reportMessage .= $translator->trans('fileField.auto.report.7', array(
				'%mappingLink%' => $report['mappingLink']
			));
		}

		return $reportMessage;
	}
}
