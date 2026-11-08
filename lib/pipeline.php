<?php
/*
 * lib/pipeline.php
 *
 * Glue between the LIMS pages and the analysis pipeline library
 * (us3lims_gridctl autoflow_util/pipeline_lib.php, which is also used by
 * submitctl.php / submitone.php, so there is only one implementation).
 *
 * The library is looked up in this order:
 *   1. $pipeline_lib, if set in config.php
 *   2. $class_dir/pipeline_lib.php (shared class directory)
 *   3. /home/us3/lims/bin/autoflow_util/pipeline_lib.php (gridctl checkout)
 */

function pipeline_lib_load()
{
  global $pipeline_lib, $class_dir;
  static $loaded = null;

  if ( $loaded !== null )
    return $loaded;

  $candidates = [];
  if ( !empty( $pipeline_lib ) )
    $candidates[] = $pipeline_lib;
  if ( !empty( $class_dir ) )
    $candidates[] = rtrim( $class_dir, '/' ) . '/pipeline_lib.php';
  $candidates[] = '/home/us3/lims/bin/autoflow_util/pipeline_lib.php';

  $loaded = false;
  foreach ( $candidates as $file )
  {
    if ( is_readable( $file ) )
    {
      require_once $file;
      $loaded = $file;
      break;
    }
  }
  return $loaded;
}

// true if the library is available and this instance has the pipeline schema
function pipeline_available( $link )
{
  global $dbname;
  static $available = null;

  if ( $available !== null )
    return $available;

  $available = false;
  if ( pipeline_lib_load() )
  {
    try
    {
      $available = pl_db_has_pipeline_schema( $link, $dbname );
    }
    catch ( Exception $e )
    {
      $available = false;
    }
  }
  return $available;
}

// may the logged-in user see / change this pipeline?
function pipeline_authorized( $pipeline )
{
  if ( $_SESSION['userlevel'] >= 4 )
    return true;

  $owner = intval( $pipeline['invID'] );
  return $_SESSION['userlevel'] >= 2 &&
         ( $owner == intval( $_SESSION['id'] ) || $owner == intval( $_SESSION['loginID'] ) );
}

// dataset key of a queue entry: cell.channel.wavelength from the raw data filename
// (run.RA.1.A.280.auc), run-qualified if the queue holds the same triple of several runs
function pipeline_dataset_keys( $requests )
{
  $short = [];
  $long  = [];
  foreach ( $requests as $i => $r )
  {
    $parts = explode( '.', $r['filename'] );
    $n     = count( $parts );
    if ( $n >= 6 )
    {
      $triple = $parts[ $n - 4 ] . '.' . $parts[ $n - 3 ] . '.' . $parts[ $n - 2 ];
      $run    = implode( '.', array_slice( $parts, 0, $n - 5 ) );
    }
    else
    {
      $triple = 'dataset' . ( $i + 1 );
      $run    = $r['filename'];
    }
    $triple    = str_replace( '|', '_', $triple );
    $short[$i] = $triple;
    $long[$i]  = str_replace( '|', '_', "$run:$triple" );
  }
  return ( count( array_unique( $short ) ) == count( $short ) ) ? $short : $long;
}

// pipeline spec "requests" from the queue built by queue_setup_1/2 (edit and noise as selected there)
function pipeline_requests_from_session()
{
  $requests = [];
  if ( !isset( $_SESSION['request'] ) )
    return $requests;

  $keys = pipeline_dataset_keys( $_SESSION['request'] );
  foreach ( $_SESSION['request'] as $i => $r )
  {
    $parts = explode( '.', $r['filename'] );
    $requests[] = [
      'key'          => $keys[ $i ],
      'triple'       => $keys[ $i ],
      'runID'        => $parts[0],
      'rawDataID'    => intval( $r['rawDataID'] ),
      'editedDataID' => intval( $r['editedDataID'] ),
      'noiseIDs'     => array_values( array_map( 'intval', array_filter( (array) ( $r['noiseIDs'] ?? [] ) ) ) ),
      'path'         => $r['path'] ?? '',
      'filename'     => $r['filename'],
      'editFilename' => $r['editFilename'] ?? '',
      'editMeniscus' => $r['editMeniscus'] ?? '',
      'dataLeft'     => $r['dataLeft'] ?? '',
      'dataRight'    => $r['dataRight'] ?? '',
    ];
  }
  return $requests;
}

// full spec from the client part (params, stages, datasets, groups) and server-side data
function pipeline_build_spec( $client, $requests, $cluster_shortname )
{
  $client = is_array( $client ) ? $client : [];
  return [
    'version'   => 2,
    'cluster'   => $cluster_shortname,
    'submitter' => [
      'personID'        => intval( $_SESSION['id'] ),
      'loginID'         => intval( $_SESSION['loginID'] ),
      'email'           => $_SESSION['email'],
      'submitter_email' => $_SESSION['submitter_email'] ?? $_SESSION['email'],
      'userlevel'       => intval( $_SESSION['userlevel'] ),
      'advancelevel'    => intval( $_SESSION['advancelevel'] ?? 0 ),
    ],
    'requests'  => $requests,
    'params'    => is_array( $client['params'] ?? null ) ? $client['params'] : [],
    'stages'    => is_array( $client['stages'] ?? null ) ? array_values( $client['stages'] ) : [],
    'datasets'  => is_array( $client['datasets'] ?? null ) ? $client['datasets'] : [],
    'groups'    => is_array( $client['groups'] ?? null ) ? array_values( $client['groups'] ) : [],
  ];
}

// custom grid models of the logged-in user (as CG_select_setup() in lib/controls.php)
function pipeline_cg_models( $link )
{
  $models = [];
  $query  = "SELECT model.modelID, description, lastUpdated " .
            "FROM modelPerson, model " .
            "WHERE personID = ? " .
            "AND modelPerson.modelID = model.modelID " .
            "AND description LIKE '%CustomGrid%' " .
            "ORDER BY lastUpdated DESC ";
  $stmt = mysqli_prepare( $link, $query );
  $personID = intval( $_SESSION['id'] );
  $stmt->bind_param( "i", $personID );
  $stmt->execute();
  $result = $stmt->get_result();
  while ( list( $modelID, $description, $lastUpdated ) = mysqli_fetch_array( $result ) )
  {
    $descr = explode( ".", $description );
    array_pop( $descr );
    $models[] = [ 'id' => intval( $modelID ), 'label' => "$lastUpdated " . implode( ".", $descr ) ];
  }
  $result->close();
  $stmt->close();
  return $models;
}
?>
