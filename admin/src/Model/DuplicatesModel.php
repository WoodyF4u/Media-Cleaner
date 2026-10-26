<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Component\Mediacleaner\Administrator\Service\QuarantineManager;
use Joomla\Component\Mediacleaner\Administrator\Service\StorageLocator;

/**
 * "Dubbele kopieën" page (v2.9.0) - see DuplicateFinder.
 */
class DuplicatesModel extends BaseDatabaseModel
{
    /**
     * @return  void
     *
     * @throws  \RuntimeException
     */
    public function authorise()
    {
        if (!Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_mediacleaner')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * @return  array
     */
    public function getItems()
    {
        $db = $this->getDatabase();

        return $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__mediacleaner_duplicates'))
                ->order($db->quoteName('size') . ' DESC, ' . $db->quoteName('relative_path'))
        )->loadAssocList();
    }

    /**
     * @return  string|null  When the last search finished.
     */
    public function getSearchedAt()
    {
        $value = StorageLocator::getState('duplicates_searched', '');

        return $value === '' ? null : $value;
    }

    /**
     * Move the chosen copies to "Tijdelijk verwijderd" - only after
     * checking once more that each is still identical to its linked twin.
     *
     * @param   integer[]  $ids  `#__mediacleaner_duplicates` ids.
     *
     * @return  array  ['moved' => int, 'changed' => int, 'errors' => int]
     */
    public function quarantine(array $ids)
    {
        $this->authorise();

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return ['moved' => 0, 'changed' => 0, 'errors' => 0];
        }

        $db   = $this->getDatabase();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__mediacleaner_duplicates'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
        )->loadAssocList();

        $fileIds = [];
        $changed = 0;
        $done    = [];

        foreach ($rows as $row) {
            $copy = JPATH_ROOT . '/' . $row['relative_path'];
            $twin = JPATH_ROOT . '/' . $row['twin_path'];

            if (!is_file($copy) || !is_file($twin) || sha1_file($copy) !== $row['hash'] || sha1_file($twin) !== $row['hash']) {
                $changed++;
                $done[] = (int) $row['id'];
                continue;
            }

            $slash = strrpos($row['relative_path'], '/');
            $path  = '/' . ($slash === false ? '' : substr($row['relative_path'], 0, $slash));
            $name  = $slash === false ? $row['relative_path'] : substr($row['relative_path'], $slash + 1);

            $id = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__mediacleaner_files'))
                    ->where($db->quoteName('path') . ' = ' . $db->quote($path === '/' ? '/' : rtrim($path, '/')))
                    ->where($db->quoteName('name') . ' = ' . $db->quote($name))
            )->loadResult();

            if ($id > 0) {
                $fileIds[] = $id;
                $done[]    = (int) $row['id'];
            }
        }

        $result = $fileIds ? (new QuarantineManager($db))->quarantine($fileIds) : ['moved' => 0, 'errors' => 0];

        if ($done) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__mediacleaner_duplicates'))
                    ->where($db->quoteName('id') . ' IN (' . implode(',', $done) . ')')
            )->execute();
        }

        return ['moved' => (int) $result['moved'], 'changed' => $changed, 'errors' => (int) $result['errors'] + \count($ids) - \count($done)];
    }
}
