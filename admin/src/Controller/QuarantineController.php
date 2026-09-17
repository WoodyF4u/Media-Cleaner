<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Utilities\ArrayHelper;

/**
 * Controller for the "quarantine" view: restore or permanently delete
 * quarantined files.
 */
class QuarantineController extends BaseController
{
    /**
     * Restore the selected quarantined files to their original location.
     *
     * @return  void
     */
    public function restore()
    {
        $this->checkToken();

        $ids = ArrayHelper::toInteger($this->input->get('cid', [], 'array'));

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\QuarantineModel $model */
        $model = $this->getModel('Quarantine');

        try {
            $result = $model->restore($ids);

            if ($result['restored'] > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_RESTORE_DONE', $result['restored']));
            }

            if ($result['errors'] > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_RESTORE_ERRORS', $result['errors']), 'warning');
            }

            if ($result['restored'] === 0 && $result['errors'] === 0) {
                $this->setMessage(Text::_('COM_MEDIACLEANER_QUARANTINE_NONE_SELECTED'), 'warning');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=quarantine', false));
    }

    /**
     * Permanently delete the selected quarantined files from disk.
     *
     * @return  void
     */
    public function purge()
    {
        $this->checkToken();

        $ids = ArrayHelper::toInteger($this->input->get('cid', [], 'array'));

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\QuarantineModel $model */
        $model = $this->getModel('Quarantine');

        try {
            $result = $model->purge($ids);

            if ($result['deleted'] > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_PURGE_DONE', $result['deleted']));
            }

            if ($result['errors'] > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_PURGE_ERRORS', $result['errors']), 'warning');
            }

            if ($result['deleted'] === 0 && $result['errors'] === 0) {
                $this->setMessage(Text::_('COM_MEDIACLEANER_QUARANTINE_NONE_SELECTED'), 'warning');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=quarantine', false));
    }

    /**
     * Streams a quarantined file's raw bytes as an image, for the
     * "Voorbeeld" thumbnail column - a plain GET request (an `<img
     * src="...task=quarantine.thumb&id=...">`), so deliberately no CSRF
     * token check here, same as any other image URL. Reaching this
     * controller at all already requires being logged into the admin
     * with access to this component (Joomla's own routing enforces
     * that); QuarantineModel::getThumbnailInfo() additionally makes sure
     * only a genuinely image-typed, quarantine-root-confined file is
     * ever actually read from disk.
     *
     * @return  void
     */
    public function thumb()
    {
        $id = $this->input->getInt('id', 0);

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\QuarantineModel $model */
        $model = $this->getModel('Quarantine');
        $info  = $model->getThumbnailInfo($id);

        // Discard anything already buffered (e.g. stray whitespace from
        // an included file) before sending binary image bytes - a
        // leaked byte before the headers/content would corrupt the
        // response.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($info === null) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $info['mime']);
        header('Content-Length: ' . (string) filesize($info['path']));
        header('Cache-Control: private, max-age=60');
        readfile($info['path']);
        exit;
    }
}
