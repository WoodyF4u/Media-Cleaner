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
 * Controller for the "files" view; handles the manual rescan action and
 * moving selected (unlinked) files to quarantine.
 */
class FilesController extends BaseController
{
    /**
     * Trigger a fresh filesystem scan and store the results in the database.
     *
     * @return  void
     */
    public function rescan()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\FilesModel $model */
        $model = $this->getModel('Files');

        try {
            $result = $model->rescan();

            $this->setMessage(
                Text::sprintf(
                    'COM_MEDIACLEANER_RESCAN_DONE',
                    $result['count'],
                    number_format($result['sizeKB'], 1, ',', '.')
                )
            );
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files', false));
    }

    /**
     * Resolves which file ids a bulk action ("Actie voor geselecteerde
     * items") in admin/tmpl/files/default.php should act on - either the
     * checkbox-selected ids on the current page (`cid[]`, the original,
     * still-default behaviour), or, when the "Selecteer alle N over alle
     * pagina's" toggle was used, every id matching the current
     * filter_linked + extension_hint combination, resolved fresh here
     * server-side (see FilesModel::getIdsForFilter()) rather than trusting
     * a giant submitted id list.
     *
     * @param   boolean  $excludeProtected  Passed straight through to
     *                                      getIdsForFilter() for the
     *                                      "select all matching" case -
     *                                      true for delete(), false for
     *                                      ignore()/unignore().
     *
     * @return  array  ['ids' => int[], 'filterBased' => bool]  filterBased
     *                 tells the caller whether an empty result means
     *                 "nothing was checked" or "everything matching was
     *                 protected" - the two need different messages.
     */
    private function resolveBulkIds($excludeProtected)
    {
        if (!$this->input->getInt('select_all_matching', 0)) {
            return [
                'ids'         => ArrayHelper::toInteger($this->input->get('cid', [], 'array')),
                'filterBased' => false,
            ];
        }

        $filterLinked      = $this->input->getWord('filter_linked', 'all');
        $extensionHint     = $this->input->getString('extension_hint', '');
        $systemAssetFilter = $this->input->getCmd('system_asset_filter', '');

        if (!in_array($filterLinked, ['all', 'linked', 'unlinked', 'ignored'], true)) {
            $filterLinked = 'all';
        }

        if (!in_array($systemAssetFilter, ['', 'system', 'other'], true)) {
            $systemAssetFilter = '';
        }

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\FilesModel $model */
        $model = $this->getModel('Files');

        return [
            'ids'         => $model->getIdsForFilter($filterLinked, $extensionHint, $excludeProtected, $systemAssetFilter),
            'filterBased' => true,
        ];
    }

    /**
     * Move the selected files to the quarantine folder, so they can be
     * reviewed and restored later instead of being deleted outright.
     *
     * @return  void
     */
    public function delete()
    {
        $this->checkToken();

        $resolved     = $this->resolveBulkIds(true);
        $ids          = $resolved['ids'];

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\FilesModel $model */
        $model = $this->getModel('Files');

        try {
            $result = $model->quarantine($ids);

            if ($result['moved'] > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_QUARANTINE_DONE', $result['moved']));
            }

            if ($result['errors'] > 0) {
                $this->setMessage(
                    Text::sprintf('COM_MEDIACLEANER_QUARANTINE_ERRORS', $result['errors']),
                    'warning'
                );
            }

            if ($result['moved'] === 0 && $result['errors'] === 0) {
                // v2.7.5: "select all matching" resolving to zero ids
                // almost always means every matching file belongs to a
                // still-active extension and got excluded by the delete
                // protection - a different, more informative message than
                // the checkbox case, where it simply means nothing was
                // ticked.
                $this->setMessage(
                    Text::_($resolved['filterBased'] ? 'COM_MEDIACLEANER_DELETE_ALL_MATCHING_NONE_DELETABLE' : 'COM_MEDIACLEANER_QUARANTINE_NONE_SELECTED'),
                    'warning'
                );
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files', false));
    }

    /**
     * Mark the selected file(s) as "ignored", hiding them from the overview
     * by default without moving or deleting anything.
     *
     * @return  void
     */
    public function ignore()
    {
        $this->checkToken();

        $ids = $this->resolveBulkIds(false)['ids'];

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\FilesModel $model */
        $model = $this->getModel('Files');

        try {
            $count = $model->setIgnored($ids, true);

            if ($count > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_IGNORE_DONE', $count));
            } else {
                $this->setMessage(Text::_('COM_MEDIACLEANER_QUARANTINE_NONE_SELECTED'), 'warning');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files', false));
    }

    /**
     * Undo "ignored" for the selected file(s), so they appear in the
     * overview again by default.
     *
     * @return  void
     */
    public function unignore()
    {
        $this->checkToken();

        $ids = $this->resolveBulkIds(false)['ids'];

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\FilesModel $model */
        $model = $this->getModel('Files');

        try {
            $count = $model->setIgnored($ids, false);

            if ($count > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_UNIGNORE_DONE', $count));
            } else {
                $this->setMessage(Text::_('COM_MEDIACLEANER_QUARANTINE_NONE_SELECTED'), 'warning');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files', false));
    }

    /**
     * "Zet systeembestanden apart": ignores every file matching the
     * request's `filter_linked` that FilesModel::getSystemAssetFileCount()
     * would count - one click for the whole heuristic-flagged set, see
     * FilesModel::ignoreSystemAssetFiles()'s docblock for what that is
     * and why it's ignore-only.
     *
     * @return  void
     */
    public function ignoreSystemAssets()
    {
        $this->checkToken();

        $filterLinked = $this->input->getWord('filter_linked', 'unlinked');

        if (!in_array($filterLinked, ['all', 'linked', 'unlinked', 'ignored'], true)) {
            $filterLinked = 'unlinked';
        }

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\FilesModel $model */
        $model = $this->getModel('Files');

        try {
            $count = $model->ignoreSystemAssetFiles($filterLinked);

            if ($count > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_IGNORE_SYSTEM_ASSETS_DONE', $count));
            } else {
                $this->setMessage(Text::_('COM_MEDIACLEANER_IGNORE_SYSTEM_ASSETS_NONE'), 'warning');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files&filter_linked=' . $filterLinked, false));
    }

    /**
     * Convert a single file to a new `.webp` file placed alongside the
     * original (nothing is overwritten or deleted). Only available from
     * the "Gekoppelde media" view.
     *
     * @return  void
     */
    public function webp()
    {
        $this->checkToken();

        $id = $this->input->getInt('id', 0);

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\FilesModel $model */
        $model = $this->getModel('Files');

        try {
            $result = $model->convertToWebp($id);

            if (!empty($result['success'])) {
                $this->setMessage(
                    Text::sprintf(
                        'COM_MEDIACLEANER_WEBP_DONE',
                        $result['webp_name'],
                        number_format($result['webp_size_kb'], 1, ',', '.'),
                        number_format($result['original_kb'], 1, ',', '.')
                    )
                );
            } else {
                $this->setMessage($this->getWebpErrorMessage($result), 'warning');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files&filter_linked=linked', false));
    }

    /**
     * Translate a convertToWebp() failure result into a user-facing message.
     *
     * @param   array  $result
     *
     * @return  string
     */
    protected function getWebpErrorMessage(array $result)
    {
        $reason = $result['reason'] ?? 'generic';

        if ($reason === 'exists') {
            return Text::sprintf('COM_MEDIACLEANER_WEBP_ERROR_EXISTS', $result['webp_name'] ?? '');
        }

        $map = [
            'unsupported_type' => 'COM_MEDIACLEANER_WEBP_ERROR_UNSUPPORTED_TYPE',
            'missing'           => 'COM_MEDIACLEANER_WEBP_ERROR_MISSING',
            'no_imagick'        => 'COM_MEDIACLEANER_WEBP_ERROR_NO_IMAGICK',
            'no_gd'             => 'COM_MEDIACLEANER_WEBP_ERROR_NO_GD',
            'read_failed'       => 'COM_MEDIACLEANER_WEBP_ERROR_READ_FAILED',
            'write_failed'      => 'COM_MEDIACLEANER_WEBP_ERROR_WRITE_FAILED',
            'not_found'         => 'COM_MEDIACLEANER_WEBP_ERROR_NOT_FOUND',
            'invalid'           => 'COM_MEDIACLEANER_WEBP_ERROR_NOT_FOUND',
            'exception'         => 'COM_MEDIACLEANER_WEBP_ERROR_GENERIC',
        ];

        return Text::_($map[$reason] ?? 'COM_MEDIACLEANER_WEBP_ERROR_GENERIC');
    }
}
