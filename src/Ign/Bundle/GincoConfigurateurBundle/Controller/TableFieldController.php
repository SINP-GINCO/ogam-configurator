<?php
namespace Ign\Bundle\GincoConfigurateurBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ign\Bundle\OGAMConfigurateurBundle\Controller\TableFieldController as TableFieldControllerBase;

class TableFieldController extends TableFieldControllerBase {

	/**
	 * Removes all the non-technical fields of a table.
	 * (including from TableField and Field). Redirects to table edition page.
	 * @Route("/models/{modelId}/tables/{format}/fields/removeall/", name="configurateur_table_remove_all_fields")
	 * @Template()
	 */
	public function removeAllFieldsAction($modelId, $format) {
		$em = $this->getDoctrine()->getManager('metadata_work');

		$tableFieldRepository = $em->getRepository('IgnOGAMConfigurateurBundle:TableField');
		$fieldRepository = $em->getRepository('IgnOGAMConfigurateurBundle:Field');
		$mappingRepository = $em->getRepository("IgnOGAMConfigurateurBundle:FieldMapping");

		$mappingRepository->removeAllExceptRefMappingsByTableFormat($format);
		$tableFieldRepository->deleteNonTechnicalAndNonRefByTableFormat($format);
		$fieldRepository->deleteNonTechnicalAndNonRefByTableFormat($format);

		$em->flush();

		return $this->redirectToRoute('configurateur_table_fields', array(
			'modelId' => $modelId,
			'format' => $format
		));
	}

	/**
	 * Removes a field from a table.
	 * (including from TableField and Field). Redirects to table edition page.
	 * @Route("/models/{modelId}/tables/{format}/fields/remove/{field}", name="configurateur_table_remove_field")
	 * @Template()
	 */
	public function removeFieldAction($modelId, $field, $format) {
		$em = $this->getDoctrine()->getManager('metadata_work');

		// Check if the field is not derived from a field included in a reference model
		$tableFieldRepository = $em->getRepository('IgnOGAMConfigurateurBundle:TableField');
		$referenceFields = $tableFieldRepository->findReferenceFields();
		if (in_array($field, array_column($referenceFields, 'data'))) {
			$this->addFlash('error', $this->get('translator')
				->trans('data.delete.ref.forbidden', array(
				'%dataName%' => $field
			)));

			return $this->redirectToRoute('configurateur_table_fields', array(
				'modelId' => $modelId,
				'format' => $format
			));
		} else {
			// remove mapping relations first
			$mappingRepository = $em->getRepository("IgnOGAMConfigurateurBundle:FieldMapping");
			$mappingRepository->removeAllByTableField($format, $field);

			$tableFieldToRemove = $em->find("IgnOGAMConfigurateurBundle:TableField", array(
				"data" => $field,
				"tableFormat" => $format
			));
			$em->remove($tableFieldToRemove);
			$em->flush();

			$fieldToRemove = $em->find("IgnOGAMConfigurateurBundle:Field", array(
				"data" => $field,
				"format" => $format
			));
			$em->remove($fieldToRemove);
			$em->flush();

			return $this->redirectToRoute('configurateur_table_fields', array(
				'modelId' => $modelId,
				'format' => $format
			));
		}
	}
}
