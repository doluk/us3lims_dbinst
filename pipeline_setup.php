<?php
/*
 * pipeline_setup.php
 *
 * Set up an analysis pipeline for the datasets in the queue (queue_setup_1/2):
 * per-dataset stages (e.g. 2DSA-IT -> 2DSA-MC, PCSA) and global stages over
 * user-defined groups of datasets. Stages start automatically when the stages
 * they depend on have finished (submitctl.php, us3lims_gridctl).
 *
 * The stage editor (js/pipeline_setup.js) builds the client part of the
 * pipeline spec; datasets, submitter and cluster are added here, and the spec
 * is validated with the shared pipeline library.
 */
include_once 'checkinstance.php';

if ( ($_SESSION['userlevel'] != 2) &&
     ($_SESSION['userlevel'] != 3) &&
     ($_SESSION['userlevel'] != 4) &&
     ($_SESSION['userlevel'] != 5) )    // only data analyst and up
{
  header('Location: index.php');
  exit();
}

include_once 'lib/motd.php';
if ( motd_isblocked() && ($_SESSION['userlevel'] < 4) )
{
  header("Location: index.php");
  exit();
}

if ( ! isset( $_SESSION['request'] ) || sizeof( $_SESSION['request'] ) < 1 )
{
  header("Location: queue_setup_1.php");
  exit();
}

include_once 'config.php';
include_once 'db.php';
include_once 'lib/utility.php';
include_once 'lib/pipeline.php';

$page_title = 'Analysis Pipeline Setup';
$css        = 'css/queue_setup.css';

if ( ! pipeline_available( $link ) )
{
  include 'header.php';
  echo "<div id='content'><h1 class='title'>$page_title</h1>\n" .
       "<p class='message'>Analysis pipelines are not available on this instance " .
       "(pipeline library or database upgrade missing). Please contact the administrator.</p>\n" .
       "<p><a href='queue_setup_3.php'>Back to queue setup</a></p></div>\n";
  include 'footer.php';
  exit();
}

$requests = pipeline_requests_from_session();

// --- defaults of the stage editor (client part of the spec) ---
$default_client = [
  'params' => [
    's_value_min' => 1, 's_value_max' => 10, 's_grid_points' => 64,
    'ff0_min' => 1, 'ff0_max' => 4, 'ff0_grid_points' => 64,
    'grid' => [ 'type' => 'uniform' ],
    'simpoints-value' => 200, 'band_volume-value' => 0.015,
  ],
  'stages' => [
    [ 'id' => 'it', 'method' => '2DSA_IT', 'enabled' => true,  'after' => [],
      'params' => [ 'noise_fit' => 'ti+ri', 'max_iterations' => 10 ] ],
    [ 'id' => 'mc', 'method' => '2DSA_MC', 'enabled' => true,  'after' => [ 'it' ],
      'params' => [ 'mc_iterations' => 100 ], 'noise_from' => 'it' ],
    [ 'id' => 'pc', 'method' => 'PCSA',    'enabled' => false, 'after' => [ 'it' ],
      'params' => [ 'curve_type' => 'IS', 'pcsa_solute_type' => '013.skv' ], 'noise_from' => 'it' ],
  ],
  'datasets' => new stdClass(),
  'groups'   => [],
];

$errors   = [];
$summary  = null;
$client   = $default_client;
$name     = 'Pipeline ' . date( 'Y-m-d H:i' );

if ( isset( $_POST['spec_json'] ) )
{
  $client = json_decode( $_POST['spec_json'], true );
  $name   = trim( $_POST['pipeline_name'] ?? '' );
  if ( ! is_array( $client ) )
  {
    $errors[] = "the stage configuration could not be read";
    $client   = $default_client;
  }
  if ( $name === '' )
    $errors[] = "please enter a name for the pipeline";

  // cluster as offered by tigre(): "name:shortname:queue", must be authorized
  $cluster_short = '';
  if ( isset( $_POST['cluster'] ) )
  {
    $parts = explode( ':', $_POST['cluster'] );
    $cluster_short = $parts[1] ?? '';
  }
  if ( $cluster_short === '' )
    $errors[] = "please select a cluster";
  else if ( $_SESSION['userlevel'] < 4 && ! in_array( $cluster_short, $_SESSION['clusterAuth'] ?? [] ) )
    $errors[] = "you are not authorized for cluster $cluster_short";

  $spec = pipeline_build_spec( $client, $requests, $cluster_short );
  $spec = json_decode( json_encode( $spec ), true );   // plain arrays, as stored
  $exp  = pl_expand_spec( $spec );
  $errors = array_merge( $errors, $exp['errors'] );

  if ( ! count( $exp['errors'] ) )
    $summary = $exp['rows'];

  if ( ! count( $errors ) && ( $_POST['action'] ?? '' ) == 'submit' )
  {
    try
    {
      $pipelineID = pl_create_pipeline( $link, $dbname, $spec, $name, intval( $_SESSION['id'] ), $cluster_short );
      $_SESSION['pipeline_message'] = "Pipeline '$name' was submitted. Its stages start automatically.";
      header( "Location: pipeline_viewer.php?pipelineID=$pipelineID" );
      exit();
    }
    catch ( Exception $e )
    {
      $errors[] = "the pipeline could not be created: " . $e->getMessage();
    }
  }
}

// --- page ---
$config = [
  'requests'  => array_map( function( $r ) {
                   return [ 'key' => $r['key'], 'filename' => $r['filename'], 'edit' => $r['editFilename'],
                            'noises' => count( $r['noiseIDs'] ) ];
                 }, $requests ),
  'methods'   => pl_methods(),
  'paramKeys' => pl_param_keys(),
  'datasetLevelKeys' => pl_dataset_level_keys(),
  'noiseFit'  => pl_noise_fit_values(),
  'curveTypes'  => pl_pcsa_curve_types(),
  'soluteTypes' => pl_pcsa_solute_types(),
  'cgModels'  => pipeline_cg_models( $link ),
  'state'     => $client,
];

include 'header.php';

$h = function( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); };

// cluster selection as on the method pages; our own submit buttons replace tigre()'s
$cluster_html = preg_replace( "/<p><input class='submit' type='submit' name='TIGRE'[^>]*><\/p>/", '', tigre() );
if ( isset( $_POST['cluster'] ) && strpos( $cluster_html, "value='" . $h( $_POST['cluster'] ) . "'" ) !== false )
{ // keep the cluster chosen before validation
  $cluster_html = str_replace( " checked='checked'", '', $cluster_html );
  $cluster_html = str_replace( "value='" . $h( $_POST['cluster'] ) . "'", "value='" . $h( $_POST['cluster'] ) . "' checked='checked'", $cluster_html );
}
?>
<div id='content'>
  <h1 class="title"><?php echo $page_title; ?></h1>

  <p>Stages of one dataset run in the order given by their predecessors; stages that do not depend on each
     other run at the same time. A global stage starts when the stages it waits for have finished for
     <em>all</em> datasets of its group. The edit profile and noise of each dataset are the ones selected in
     the queue setup; meniscus fitting is done beforehand.</p>

<?php
if ( count( $errors ) )
{
  echo "  <div class='message' id='pipeline_errors'><b>Please correct the following:</b><ul>\n";
  foreach ( $errors as $e )
    echo "    <li>" . $h( $e ) . "</li>\n";
  echo "  </ul></div>\n";
}

if ( $summary !== null && ! count( $errors ) )
{
  echo "  <fieldset id='pipeline_summary'><legend>Validation passed &ndash; stages that will be created</legend>\n" .
       "  <table class='pipeline'><tr><th>Dataset / group</th><th>Stage</th><th>Method</th><th>Waits for</th><th>Noise subtracted</th></tr>\n";
  foreach ( $summary as $k => $row )
  {
    $who   = $row['rowKind'] == 'group' ? "group " . $row['groupName'] . " (" . implode( ', ', $row['members'] ) . ")"
                                        : $row['datasetKey'];
    $after = implode( ', ', array_map( function( $a ) { $p = explode( '|', $a ); return "$p[1]/$p[2]"; }, $row['after'] ) );
    $nf    = $row['noise_from'];
    if ( is_array( $nf ) )
      $nf  = isset( $nf['row'] ) ? "from stage " . explode( '|', $nf['row'] )[2] : "from members' stage " . $nf['member_stage'];
    else
      $nf  = $nf == 'queue_setup' ? 'as selected in queue setup' : 'none';
    echo "    <tr><td>" . $h( $who ) . "</td><td>" . $h( $row['stageKey'] ) . "</td><td>" . $h( $row['method'] ) .
         "</td><td>" . $h( $after ?: '-' ) . "</td><td>" . $h( $nf ) . "</td></tr>\n";
  }
  echo "  </table></fieldset>\n";
}
?>

  <form method="post" action="<?php echo $h( $_SERVER['PHP_SELF'] ); ?>" id="pipeline_form">
    <fieldset>
      <legend>Pipeline</legend>
      <label>Name <input type="text" name="pipeline_name" id="pipeline_name" size="50"
                         value="<?php echo $h( $name ); ?>" /></label>
    </fieldset>

    <div id="pipeline_editor"><noscript>The stage editor needs JavaScript.</noscript></div>

    <input type="hidden" name="spec_json" id="spec_json" value="" />
    <input type="hidden" name="action" id="pipeline_action" value="validate" />

<?php echo $cluster_html; ?>

    <p><input type="submit" class="submit" id="pipeline_validate" value="Validate"
              onclick="document.getElementById('pipeline_action').value='validate';" />
       <input type="submit" class="submit" id="pipeline_submit" value="Submit Pipeline"
              onclick="document.getElementById('pipeline_action').value='submit';" />
       <input type="button" value="Back to Queue Setup" onclick="window.location='queue_setup_3.php';" /></p>
  </form>
</div>

<style>
  table.pipeline { border-collapse: collapse; margin: 0.3em 0; }
  table.pipeline th, table.pipeline td { border: 1px solid #ccc; padding: 2px 5px; vertical-align: top; text-align: left; }
  #pipeline_editor fieldset { margin: 0.8em 0; }
  #pipeline_editor .hint { color: #555; font-size: 90%; }
  /* css/queue_setup.css makes every input 25em wide */
  #pipeline_editor input[type=checkbox], #clusters input[type=radio] { width: auto; }
  #pipeline_editor input[type=text] { width: 12em; }
  #pipeline_editor input[type=text].num { width: 5em; }
  #pipeline_editor label { white-space: nowrap; margin-right: 0.6em; }
  #pipeline_editor .group { border: 1px solid #aaa; padding: 0.5em; margin: 0.5em 0; }
</style>
<script type="text/javascript">
  var PIPELINE_CONFIG = <?php echo json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
</script>
<script type="text/javascript" src="js/pipeline_setup.js"></script>

<?php
include 'footer.php';
?>
