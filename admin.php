<?php
defined('GLM_PATH') or die('Hacking attempt!');

// +---------------------------------------------------------------------------+
// | Access control : webmaster only (page, actions and AJAX browser)          |
// +---------------------------------------------------------------------------+

check_status(ACCESS_WEBMASTER);

// +---------------------------------------------------------------------------+
// | Helper functions                                                          |
// +---------------------------------------------------------------------------+

function glm_path_allowed($path, $browser_root)
{
  $root = rtrim($browser_root, '/');
  if ($root === '' || $root === '/') return true;
  return $path === $root || strpos($path . '/', $root . '/') === 0;
}

function glm_is_restricted($path)
{
  $path = rtrim($path, '/');

  // Refusés tels quels (leurs sous-dossiers peuvent être légitimes, ex. /run/media)
  if (in_array($path, array('', '/usr', '/run')))
  {
    return true;
  }

  // Refusés ainsi que tous leurs sous-dossiers
  $restricted_trees = array(
    '/etc', '/bin', '/sbin', '/usr/bin', '/usr/sbin',
    '/proc', '/sys', '/dev', '/boot', '/lib', '/lib64',
  );
  foreach ($restricted_trees as $tree)
  {
    if ($path === $tree || strpos($path . '/', $tree . '/') === 0)
    {
      return true;
    }
  }
  return false;
}

function glm_valid_link_name($name)
{
  return $name !== '.' && $name !== '..'
    && preg_match('/^[a-zA-Z0-9_.-]+\z/', $name) === 1;
}

// Dossiers autorisés par open_basedir (tableau vide si aucune restriction)
function glm_open_basedir_dirs()
{
  $obd = (string)ini_get('open_basedir');
  if ($obd === '')
  {
    return array();
  }
  $dirs = array();
  foreach (explode(PATH_SEPARATOR, $obd) as $d)
  {
    $d = trim($d);
    if ($d === '' || $d === '.')
    {
      continue;
    }
    $real = @realpath($d);
    $d = ($real !== false) ? $real : $d;
    $d = rtrim(str_replace('\\', '/', $d), '/');
    $dirs[] = ($d === '') ? '/' : $d;
  }
  return array_values(array_unique($dirs));
}

// Normalise un chemin absolu saisi (sans le résoudre) ; false s'il est suspect
function glm_normalize_path($path)
{
  $path = str_replace('\\', '/', (string)$path);
  if ($path === '' || $path[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)#', $path))
  {
    return false;
  }
  $path = rtrim(preg_replace('#/+#', '/', $path), '/');
  return ($path === '') ? '/' : $path;
}

// Vrai si $child est $parent ou l'un de ses sous-dossiers
function glm_is_under($child, $parent)
{
  if ($parent === '/') return true;
  return $child === $parent || strpos($child . '/', $parent . '/') === 0;
}

// Vrai si open_basedir est actif et que $path n'est dans aucun dossier autorisé
function glm_outside_open_basedir($path, $obd_dirs)
{
  if (empty($obd_dirs))
  {
    return false;
  }
  foreach ($obd_dirs as $d)
  {
    if (glm_is_under($path, $d))
    {
      return false;
    }
  }
  return true;
}

// Dossiers autorisés par open_basedir situés sous $dir et sous la racine de l'explorateur
function glm_open_basedir_entries($dir, $obd_dirs, $browser_root)
{
  $entries = array();
  foreach ($obd_dirs as $d)
  {
    if ($d === $dir || !glm_is_under($d, $dir) || !glm_path_allowed($d, $browser_root))
    {
      continue;
    }
    if (!@is_dir($d))
    {
      continue;
    }
    $name = ($dir === '/') ? ltrim($d, '/') : substr($d, strlen($dir) + 1);
    $entries[] = array('name' => $name, 'path' => $d);
  }
  usort($entries, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return $entries;
}

function glm_list_symlinks($galleries_dir)
{
  $symlinks = array();
  if (!$galleries_dir || !is_dir($galleries_dir))
  {
    return $symlinks;
  }
  $entries = @scandir($galleries_dir);
  if ($entries === false)
  {
    return $symlinks;
  }
  foreach ($entries as $entry)
  {
    if ($entry === '.' || $entry === '..')
    {
      continue;
    }
    $full_path = $galleries_dir . '/' . $entry;
    if (is_link($full_path))
    {
      $target = readlink($full_path);
      $real   = realpath($full_path);
      $symlinks[] = array(
        'name'       => $entry,
        'target'     => $target,
        'accessible' => ($real !== false && is_dir($real)),
      );
    }
  }
  return $symlinks;
}

// +---------------------------------------------------------------------------+
// | AJAX handler                                                              |
// +---------------------------------------------------------------------------+

if (isset($_GET['ajax']))
{
  global $conf;
  $browser_root  = isset($conf['galleries_link_manager']['browser_root'])
    ? $conf['galleries_link_manager']['browser_root']
    : '/';
  $hide_at_dirs     = isset($conf['galleries_link_manager']['hide_at_dirs'])
    ? (bool)$conf['galleries_link_manager']['hide_at_dirs']
    : true;
  $hide_system_dirs = isset($conf['galleries_link_manager']['hide_system_dirs'])
    ? (bool)$conf['galleries_link_manager']['hide_system_dirs']
    : true;

  while (ob_get_level() > 0) { ob_end_clean(); }
  header('Content-Type: application/json; charset=utf-8');

  $dir  = isset($_GET['dir']) ? $_GET['dir'] : $browser_root;
  $real = @realpath($dir);

  if ($real === false || !is_dir($real))
  {
    // Dossier non lisible : est-ce à cause d'open_basedir ?
    $obd_dirs = glm_open_basedir_dirs();
    $norm     = glm_normalize_path($dir);

    if ($norm !== false && glm_outside_open_basedir($norm, $obd_dirs))
    {
      $entries = glm_path_allowed($norm, $browser_root)
        ? glm_open_basedir_entries($norm, $obd_dirs, $browser_root)
        : array();

      if (empty($entries))
      {
        echo json_encode(array('success' => false, 'error' => l10n('glm_browser_outside_obd')));
        exit();
      }

      // Dossier ancêtre de dossiers autorisés : on propose ceux-ci comme points d'entrée
      $parent = dirname($norm);
      if ($norm === '/' || $norm === rtrim($browser_root, '/') || !glm_path_allowed($parent, $browser_root))
      {
        $parent = null;
      }
      echo json_encode(array(
        'success' => true,
        'current' => $norm,
        'parent'  => $parent,
        'entries' => $entries,
        'notice'  => l10n('glm_browser_obd_notice'),
      ));
      exit();
    }

    echo json_encode(array('success' => false, 'error' => 'Invalid directory'));
    exit();
  }

  if (!glm_path_allowed($real, $browser_root))
  {
    echo json_encode(array('success' => false, 'error' => 'Access denied'));
    exit();
  }

  $items = @scandir($real);
  if ($items === false)
  {
    echo json_encode(array('success' => false, 'error' => 'Permission denied'));
    exit();
  }

  $entries = array();
  foreach ($items as $item)
  {
    if ($item === '.' || $item === '..') continue;
    if ($hide_at_dirs && $item[0] === '@') continue;
    if ($hide_system_dirs && in_array($item, array(
      'boot', 'dev', 'etc', 'logs', 'lost+found',
      'proc', 'root', 'run', 'snap', 'srv', 'sys', 'tmp',
    ))) continue;
    $item_path = $real . '/' . $item;
    if (is_dir($item_path) && !is_link($item_path))
    {
      $entries[] = array('name' => $item, 'path' => $item_path);
    }
  }
  usort($entries, function($a, $b) { return strcasecmp($a['name'], $b['name']); });

  // Parent : null si on est déjà à la racine autorisée
  $parent = dirname($real);
  if ($real === rtrim($browser_root, '/') || !glm_path_allowed($parent, $browser_root))
  {
    $parent = null;
  }

  echo json_encode(array(
    'success' => true,
    'current' => $real,
    'parent'  => $parent,
    'entries' => $entries,
  ));
  exit();
}

global $conf;
$glm_conf     = $conf['galleries_link_manager'];
$browser_root = isset($glm_conf['browser_root']) ? $glm_conf['browser_root'] : '/';
$galleries_dir = realpath(PHPWG_ROOT_PATH . 'galleries');

// --- DIAGNOSTIC ---
$glm_diag = array(
  'php'         => PHP_VERSION,
  'symlink_fn'  => function_exists('symlink') ? 'yes' : 'no',
  'galleries'   => $galleries_dir,
  'writable'    => is_writable($galleries_dir) ? 'yes' : 'no',
  'open_basedir'=> ini_get('open_basedir') ?: '(none)',
  'server_user' => function_exists('posix_getpwuid')
                   ? posix_getpwuid(posix_geteuid())['name']
                   : get_current_user(),
);
$template->assign('GLM_DIAG', json_encode($glm_diag, JSON_PRETTY_PRINT));
// --- FIN DIAGNOSTIC ---



// +---------------------------------------------------------------------------+
// | Process POST actions                                                      |
// +---------------------------------------------------------------------------+

$page_action = isset($_POST['action']) ? $_POST['action'] : '';

if (!empty($page_action))
{
  check_pwg_token();

  if ($page_action === 'save_config')
  {
    $new_root = trim($_POST['browser_root']);
    if (empty($new_root))
    {
      $page['errors'][] = l10n('glm_config_root_required');
    }
    else
    {
      $glm_conf['browser_root']  = $new_root;
      $glm_conf['hide_at_dirs']     = !empty($_POST['hide_at_dirs']);
      $glm_conf['hide_system_dirs'] = !empty($_POST['hide_system_dirs']);
      $browser_root = $new_root;
      conf_update_param('galleries_link_manager', $glm_conf, true);
      $conf['galleries_link_manager'] = $glm_conf;
      $page['infos'][] = l10n('glm_config_saved');

      $obd_dirs = glm_open_basedir_dirs();
      $norm     = glm_normalize_path($new_root);
      if (@realpath($new_root) === false && $norm !== false
        && glm_outside_open_basedir($norm, $obd_dirs))
      {
        $page['warnings'][] = l10n('glm_config_root_outside_obd')
          . ' (open_basedir : ' . implode(PATH_SEPARATOR, $obd_dirs) . ')';
      }
    }
  }
  elseif ($page_action === 'create' || $page_action === 'edit')
  {
    $link_name = isset($_POST['link_name']) ? trim($_POST['link_name']) : '';
    $target    = isset($_POST['target'])    ? trim($_POST['target'])    : '';

    if (!glm_valid_link_name($link_name))
    {
      $page['errors'][] = l10n('glm_invalid_link_name');
    }
    elseif (empty($target))
    {
      $page['errors'][] = l10n('glm_target_required');
    }
    else
    {
      $real_target = @realpath($target);

      // DIAG
      if ($real_target === false)
      {
  $ob = ini_get('open_basedir');
  $detail = $ob ? ' (open_basedir: ' . $ob . ')' : '';
  $page['errors'][] = l10n('glm_target_not_found') . $detail;
      }
      elseif (!is_dir($real_target))
      {
        $page['errors'][] = l10n('glm_target_not_dir');
      }
      elseif (glm_is_restricted($real_target))
      {
        $page['errors'][] = l10n('glm_target_restricted');
      }
      elseif (!glm_path_allowed($real_target, $browser_root))
      {
        $page['errors'][] = l10n('glm_target_outside_root');
      }
      else
      {
        $link_path = $galleries_dir . '/' . $link_name;

        if (!function_exists('symlink'))
        {
          $page['errors'][] = l10n('glm_symlink_disabled');
        }
        elseif ($page_action === 'edit' && is_link($link_path))
        {
          // edit : on supprime l'ancien lien puis on crée le nouveau
          @unlink($link_path);
          error_clear_last();
          if (@symlink($real_target, $link_path))
          {
            $page['infos'][] = l10n('glm_link_updated');
          }
          else
          {
            $err = error_get_last();
            $detail = $err ? ' — ' . $err['message'] : '';
            $page['errors'][] = l10n('glm_link_failed') . $detail;
          }
        }
        elseif (!is_link($link_path) && !file_exists($link_path))
        {
          error_clear_last();
          if (@symlink($real_target, $link_path))
          {
            $page['infos'][] = l10n('glm_link_created');
          }
          else
          {
            $err = error_get_last();
            $detail = $err ? ' — ' . $err['message'] : '';
            $page['errors'][] = l10n('glm_link_failed') . $detail;
          }
        }
        else
        {
          $page['errors'][] = l10n('glm_name_exists');
        }
      }
    }
  }
  elseif ($page_action === 'delete')
  {
    $link_name = isset($_POST['link_name']) ? trim($_POST['link_name']) : '';
    $link_path = $galleries_dir . '/' . $link_name;

    if (!glm_valid_link_name($link_name))
    {
      $page['errors'][] = l10n('glm_invalid_link_name');
    }
    elseif (!is_link($link_path))
    {
      $page['errors'][] = l10n('glm_not_a_link');
    }
    elseif (@unlink($link_path))
    {
      $page['infos'][] = l10n('glm_link_deleted');
    }
    else
    {
      $page['errors'][] = l10n('glm_delete_failed');
    }
  }
}

// +---------------------------------------------------------------------------+
// | Build data for template                                                   |
// +---------------------------------------------------------------------------+

$symlinks = glm_list_symlinks($galleries_dir);

// +---------------------------------------------------------------------------+
// | Template assignment                                                       |
// +---------------------------------------------------------------------------+

$template->assign(array(
  'GLM_SYMLINKS'      => $symlinks,
  'GLM_GALLERIES_DIR' => $galleries_dir,
  'GLM_BROWSER_ROOT'  => $browser_root,
  'GLM_HIDE_AT_DIRS'     => !empty($glm_conf['hide_at_dirs']),
  'GLM_HIDE_SYSTEM_DIRS' => !empty($glm_conf['hide_system_dirs']),
  'GLM_FORM_ACTION'   => get_root_url() . 'admin.php?page=plugin-' . GLM_ID,
  'PWG_TOKEN'         => get_pwg_token(),

  'GLM_L_TITLE'         => l10n('glm_title'),
  'GLM_L_COL_NAME'      => l10n('glm_col_name'),
  'GLM_L_COL_TARGET'    => l10n('glm_col_target'),
  'GLM_L_COL_STATUS'    => l10n('glm_col_status'),
  'GLM_L_COL_ACTIONS'   => l10n('glm_col_actions'),
  'GLM_L_OK'            => l10n('glm_status_ok'),
  'GLM_L_BROKEN'        => l10n('glm_status_broken'),
  'GLM_L_NO_LINKS'      => l10n('glm_no_links'),
  'GLM_L_EDIT'          => l10n('glm_edit'),
  'GLM_L_DELETE'        => l10n('glm_delete'),
  'GLM_L_NEW_TITLE'     => l10n('glm_new_title'),
  'GLM_L_EDIT_TITLE'    => l10n('glm_edit_title'),
  'GLM_L_NAME_LBL'      => l10n('glm_name_label'),
  'GLM_L_TARGET_LBL'    => l10n('glm_target_label'),
  'GLM_L_BROWSE'        => l10n('glm_browse'),
  'GLM_L_CREATE'        => l10n('glm_create'),
  'GLM_L_UPDATE'        => l10n('glm_update'),
  'GLM_L_CANCEL'        => l10n('glm_cancel'),
  'GLM_L_BR_TITLE'      => l10n('glm_browser_title'),
  'GLM_L_BR_SELECT'     => l10n('glm_browser_select'),
  'GLM_L_BR_LOADING'    => l10n('glm_browser_loading'),
  'GLM_L_GALLERIES'     => l10n('glm_galleries_dir'),
  'GLM_L_CFG_TITLE'     => l10n('glm_config_title'),
  'GLM_L_CFG_ROOT_LBL'  => l10n('glm_config_root_label'),
  'GLM_L_CFG_ROOT_HELP' => l10n('glm_config_root_help'),
  'GLM_L_CFG_SAVE'         => l10n('glm_config_save'),
  'GLM_L_USAGE_TIP'        => l10n('glm_usage_tip'),
  'GLM_L_CFG_HIDE_AT_LBL' => l10n('glm_config_hide_at_label'),
  'GLM_L_CFG_HIDE_AT_HELP'      => l10n('glm_config_hide_at_help'),
  'GLM_L_CFG_HIDE_SYS_LBL'     => l10n('glm_config_hide_sys_label'),
  'GLM_L_CFG_HIDE_SYS_HELP'    => l10n('glm_config_hide_sys_help'),

  'GLM_JS_CONFIG' => json_encode(array(
    'ajaxUrl'     => get_root_url() . 'admin.php?page=plugin-' . GLM_ID . '&ajax=browse_dir&dir=',
    'browserRoot' => $browser_root,
    'labels'      => array(
      'empty'         => l10n('glm_browser_empty'),
      'error'         => l10n('glm_browser_error'),
      'newTitle'      => l10n('glm_new_title'),
      'editTitle'     => l10n('glm_edit_title'),
      'create'        => l10n('glm_create'),
      'update'        => l10n('glm_update'),
      'confirmDelete' => l10n('glm_confirm_delete'),
    ),
  ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
));

$template->set_filename('glm_admin', GLM_PATH . 'template/admin.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'glm_admin');
