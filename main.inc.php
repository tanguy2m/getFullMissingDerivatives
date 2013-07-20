<?php
/*
Plugin Name: getFullMissingDerivatives
Version: 2.4.a
Description: Add a web-service for custom derivatives generation
Author: tanguy2m
*/

function ws_getFullMissingDerivatives($params, &$service)
{
  if (!is_admin())
  {
    return new PwgError(403, 'Forbidden');
  }

  if ( empty($params['types']) )
  {
    $types = array_keys(ImageStdParams::get_defined_type_map());
  }
  else
  {
    if (in_array('custom', $params['types']) &&
	  (empty($params['custom_width']) || empty($params['custom_height'])))
        return new PwgError(WS_ERR_INVALID_PARAM, "Missing custom parameters");
    
    $types = array_intersect(array('custom')+array_keys(ImageStdParams::get_defined_type_map()), $params['types']);

    if (count($types)==0)
    {
      return new PwgError(WS_ERR_INVALID_PARAM, "Invalid types");
    }
  }

  if ( ($max_urls = intval($params['max_urls'])) <= 0)
  {
    return new PwgError(WS_ERR_INVALID_PARAM, "Invalid max_urls");
  }

  list($max_id, $image_count) = pwg_db_fetch_row( pwg_query('SELECT MAX(id)+1, COUNT(*) FROM '.IMAGES_TABLE) );

  if (0 == $image_count)
  {
    return array();
  }

  $start_id = intval($params['prev_page']);
  if ($start_id<=0)
  {
    $start_id = $max_id;
  }

  $uid = '&b='.time();
  global $conf;
  $conf['question_mark_in_urls'] = $conf['php_extension_in_urls'] = true;
  $conf['derivative_url_style']=2; //script

  $qlimit = min(5000, ceil(max($image_count/500, $max_urls/count($types))));
  $where_clauses = ws_std_image_sql_filter( $params, '' );
  $where_clauses[] = 'id<start_id';
  if ( !empty($params['ids']) )
  {
    $where_clauses[] = 'id IN ('.implode(',',$params['ids']).')';
  }

  $query_model = 'SELECT id, path, representative_ext, width,height,rotation
    FROM '.IMAGES_TABLE.'
    WHERE '.implode(' AND ', $where_clauses).'
    ORDER BY id DESC
    LIMIT '.$qlimit;

  $urls=array();
  do
  {
    $result = pwg_query( str_replace('start_id', $start_id, $query_model));
    $is_last = pwg_db_num_rows($result) < $qlimit;
    while ($row=pwg_db_fetch_assoc($result))
    {
      $start_id = $row['id'];
      $src_image = new SrcImage($row);
      if ($src_image->is_mimetype())
      continue;
      foreach($types as $type)
      {
		if ($type=='custom'){
		  $derivative = new DerivativeImage(
			ImageStdParams::get_custom(
			  $params['custom_width'],
			  $params['custom_height'],
			  $params['custom_crop'],
			  $params['custom_min_width'],
			  $params['custom_min_height']
			),
			$src_image
		  );
		} else {
		  $derivative = new DerivativeImage($type, $src_image);
		}
		
        if ($type != $derivative->get_type())
        continue;
        if (@filemtime($derivative->get_path())===false)
        {
          $urls[] = $derivative->get_url().$uid;
        }
      }
      if (count($urls)>=$max_urls && !$is_last)
      break;
    }
    if ($is_last)
    {
      $start_id = 0;
    }
  }while (count($urls)<$max_urls && $start_id);

  $ret = array();
  if ($start_id)
  {
    $ret['next_page']=$start_id;
  }
  $ret['urls']=$urls;
  return $ret;
}

// Déclaration des web-services
add_event_handler('ws_add_methods', 'extend_ws');
function extend_ws($arr) {
  $service = &$arr[0];
  $service->addMethod(
    'pwg.getFullMissingDerivatives',
    'ws_getFullMissingDerivatives',
    array(
      'types'=>array('default'=>array(),'flags'=>WS_PARAM_FORCE_ARRAY),
      'custom_width' => array('default'=>null),
      'custom_height' => array('default'=>null),
      'custom_crop' => array('default'=>0),
      'custom_min_width' => array('default'=>null),
      'custom_min_height' => array('default'=>null),
      'ids' =>array('default'=>array(),'flags'=>WS_PARAM_FORCE_ARRAY),
      'max_urls' =>   array('default'=>200),
      'prev_page' =>  array('default'=>null),
	  'f_min_rate' => array('default'=>null),
	  'f_max_rate' => array('default'=>null),
	  'f_min_hit' =>  array('default'=>null),
	  'f_max_hit' =>  array('default'=>null),
	  'f_min_date_available' => array('default'=>null),
	  'f_max_date_available' => array('default'=>null),
	  'f_min_date_created' =>   array('default'=>null),
	  'f_max_date_created' =>   array('default'=>null),
	  'f_min_ratio' => array('default'=>null),
	  'f_max_ratio' => array('default'=>null),
	  'f_max_level' => array('default'=>null)
    ),
    "retrieves a list of derivatives to build<br>For custom derivatives, add 'custom' in the <i>types</i> field and use <i>custom_*</i> fields for params"
  );
}

?>