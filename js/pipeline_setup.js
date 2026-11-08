/*
 * pipeline_setup.js
 *
 * Stage editor of pipeline_setup.php. Edits the client part of an analysis
 * pipeline spec (doc/analysis_pipeline_design.md in us3lims_gridctl):
 *
 *   { params:   { key: value },                                   shared parameters
 *     stages:   [ { id, method, enabled, after:[ids], params, noise_from } ],      per dataset
 *     datasets: { key: { params, stages: { id: { enabled, params, noise_from } } } },  overrides
 *     groups:   [ { name, members:[keys], params,
 *                   stages: [ { id, method, enabled, after_member:[ids], after:[ids], params, noise_from } ] } ] }
 *
 * The server validates the spec (pl_expand_spec) and shows errors and a summary.
 * Input: PIPELINE_CONFIG (see pipeline_setup.php). Output: hidden field spec_json.
 */
( function () {
  'use strict';

  var C     = window.PIPELINE_CONFIG;
  var state = normalise( C.state );
  var root  = document.getElementById( 'pipeline_editor' );

  // ------------------------------------------------------------ helpers

  function normalise( s ) {
    s = JSON.parse( JSON.stringify( s || {} ) );
    if ( !s.params || Array.isArray( s.params ) ) s.params = {};
    if ( !Array.isArray( s.stages ) ) s.stages = [];
    if ( !s.datasets || Array.isArray( s.datasets ) ) s.datasets = {};
    if ( !Array.isArray( s.groups ) ) s.groups = [];
    s.stages.forEach( function ( st ) { st.after = st.after || []; st.params = objOf( st.params ); } );
    s.groups.forEach( function ( g ) {
      g.members = g.members || []; g.params = objOf( g.params ); g.stages = g.stages || [];
      g.stages.forEach( function ( st ) { st.after = st.after || []; st.after_member = st.after_member || []; st.params = objOf( st.params ); } );
    } );
    return s;
  }
  function objOf( o ) { return ( o && !Array.isArray( o ) && typeof o === 'object' ) ? o : {}; }

  function esc( s ) {
    return String( s === undefined || s === null ? '' : s )
      .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
      .replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
  }

  function methodsOf( scope ) {
    return Object.keys( C.methods ).filter( function ( m ) { return C.methods[ m ].scope === scope; } );
  }
  function isIt( m ) { return /_IT$/.test( m ); }
  function isMc( m ) { return /_MC$/.test( m ); }
  function family( m ) { return C.methods[ m ] ? C.methods[ m ].family : ''; }

  function parseValue( v ) {
    v = String( v ).trim();
    if ( v === '' ) return undefined;
    return /^-?\d+(\.\d+)?([eE]-?\d+)?$/.test( v ) ? Number( v ) : v;
  }

  // path: array of keys into state; value undefined deletes the key
  function setPath( path, value ) {
    var o = state;
    for ( var i = 0; i < path.length - 1; i++ ) {
      var k = path[ i ];
      if ( o[ k ] === undefined || o[ k ] === null ) o[ k ] = /^\d+$/.test( path[ i + 1 ] ) ? [] : {};
      o = o[ k ];
    }
    var last = path[ path.length - 1 ];
    if ( value === undefined ) delete o[ last ]; else o[ last ] = value;
  }
  function getPath( path ) {
    var o = state;
    for ( var i = 0; i < path.length; i++ ) {
      if ( o === undefined || o === null ) return undefined;
      o = o[ path[ i ] ];
    }
    return o;
  }
  function p( path ) { return esc( JSON.stringify( path ) ); }

  function newStageId( existing ) {
    for ( var n = 1; ; n++ ) if ( existing.indexOf( 's' + n ) < 0 ) return 's' + n;
  }

  // ------------------------------------------------------------ controls

  function numInput( path, value, title ) {
    return "<input type='text' class='num' data-path='" + p( path ) + "' data-type='val' value='" +
           esc( value === undefined ? '' : value ) + "'" + ( title ? " title='" + esc( title ) + "'" : '' ) + " />";
  }

  function select( path, options, value, kind ) {   // options: [ [value, label], ... ]
    var h = "<select data-path='" + p( path ) + "' data-type='" + ( kind || 'str' ) + "'>";
    options.forEach( function ( o ) {
      h += "<option value='" + esc( o[ 0 ] ) + "'" + ( String( o[ 0 ] ) === String( value ) ? " selected='selected'" : '' ) +
           ">" + esc( o[ 1 ] ) + "</option>";
    } );
    return h + "</select>";
  }

  function gridSelect( path, value, inherit ) {
    var opts = [];
    if ( inherit ) opts.push( [ '', '(shared setting)' ] );
    opts.push( [ 'uniform', 'uniform grid' ] );
    C.cgModels.forEach( function ( m ) { opts.push( [ 'custom:' + m.id, 'custom grid: ' + m.label ] ); } );
    var cur = !value ? '' : ( value.type === 'custom' ? 'custom:' + value.modelID : 'uniform' );
    return select( path, opts, cur, 'grid' );
  }

  // generic key/value editor for "any parameter" of an object; keys in skip have own controls
  function paramEditor( path, skip, hint ) {
    var obj  = getPath( path ) || {};
    var keys = Object.keys( obj ).filter( function ( k ) { return skip.indexOf( k ) < 0; } );
    var h = "<div class='params'>";
    keys.forEach( function ( k ) {
      h += "<div>" + esc( k ) + " = " + numInput( path.concat( [ k ] ), obj[ k ] ) +
           " <a href='#' data-act='delparam' data-path='" + p( path.concat( [ k ] ) ) + "'>remove</a></div>";
    } );
    var opts = "<option value=''>add parameter&hellip;</option>";
    Object.keys( C.paramKeys ).forEach( function ( k ) {
      if ( k === 'grid' || skip.indexOf( k ) >= 0 || keys.indexOf( k ) >= 0 ) return;
      opts += "<option value='" + esc( k ) + "'>" + esc( k ) + "</option>";
    } );
    h += "<select data-act='addparam' data-path='" + p( path ) + "'>" + opts + "</select>";
    if ( hint ) h += " <span class='hint'>" + hint + "</span>";
    return h + "</div>";
  }

  // ------------------------------------------------------------ sections

  function renderShared() {
    var P = state.params;
    var h = "<fieldset><legend>Shared parameters (all stages and datasets unless overridden)</legend>" +
            "<table class='pipeline'><tr><th colspan='2'>2DSA</th><th colspan='2'>PCSA</th><th colspan='2'>Simulation</th></tr>";
    var r2 = [ [ 's_value_min', 's min' ], [ 's_value_max', 's max' ], [ 's_grid_points', 's grid points' ],
               [ 'ff0_min', 'f/f0 min' ], [ 'ff0_max', 'f/f0 max' ], [ 'ff0_grid_points', 'f/f0 grid points' ] ];
    var rp = [ [ 'x_min', 'x min' ], [ 'x_max', 'x max' ], [ 'y_min', 'y min' ], [ 'y_max', 'y max' ],
               [ 'vars_count', 'variations' ], [ 'curves_points', 'curve points' ] ];
    var rs = [ [ 'simpoints-value', 'simulation points' ], [ 'band_volume-value', 'band volume (band-forming only)' ] ];
    for ( var i = 0; i < 6; i++ ) {
      h += "<tr><td>" + r2[ i ][ 1 ] + "</td><td>" + numInput( [ 'params', r2[ i ][ 0 ] ], P[ r2[ i ][ 0 ] ] ) + "</td>" +
           "<td>" + rp[ i ][ 1 ] + "</td><td>" + numInput( [ 'params', rp[ i ][ 0 ] ], P[ rp[ i ][ 0 ] ] ) + "</td>";
      if ( i < 2 )
        h += "<td>" + rs[ i ][ 1 ] + "</td><td>" + numInput( [ 'params', rs[ i ][ 0 ] ], P[ rs[ i ][ 0 ] ] ) + "</td>";
      else if ( i === 2 )
        h += "<td>radial grid</td><td>" + select( [ 'params', 'radial_grid' ], [ [ '', '(default)' ], [ 0, 'ASTFEM' ], [ 1, 'Claverie' ], [ 2, 'Moving hat' ] ], P.radial_grid, 'val' ) + "</td>";
      else if ( i === 3 )
        h += "<td>time grid</td><td>" + select( [ 'params', 'time_grid' ], [ [ '', '(default)' ], [ 1, 'ASTFEM' ], [ 0, 'Constant' ] ], P.time_grid, 'val' ) + "</td>";
      else
        h += "<td></td><td></td>";
      h += "</tr>";
    }
    h += "<tr><td>grid</td><td colspan='1'>" + gridSelect( [ 'params', 'grid' ], P.grid, false ) + "</td>" +
         "<td>curve type</td><td>" + select( [ 'params', 'curve_type' ], [ [ '', '(default IS)' ] ].concat( C.curveTypes.map( function ( c ) { return [ c, c ]; } ) ), P.curve_type ) + "</td>" +
         "<td>PCSA solute type</td><td>" + select( [ 'params', 'pcsa_solute_type' ], [ [ '', '(default 013.skv)' ] ].concat( C.soluteTypes.map( function ( c ) { return [ c, c ]; } ) ), P.pcsa_solute_type ) + "</td></tr>";
    h += "</table>";
    var shown = r2.concat( rp, rs ).map( function ( x ) { return x[ 0 ]; } )
                  .concat( [ 'radial_grid', 'time_grid', 'grid', 'curve_type', 'pcsa_solute_type' ] );
    h += "<p>Further shared parameters: " + paramEditor( [ 'params' ], shown ) + "</p></fieldset>";
    return h;
  }

  function stageSettings( base, st ) {   // method specific controls of a stage (dataset or group)
    var h = '';
    if ( C.methods[ st.method ] && C.methods[ st.method ].noise_fit )
      h += "<div>fit noise " + select( base.concat( [ 'params', 'noise_fit' ] ),
             [ [ '', '(shared)' ] ].concat( C.noiseFit.map( function ( n ) { return [ n, n ]; } ) ), st.params.noise_fit ) + "</div>";
    if ( isIt( st.method ) && family( st.method ) === '2dsa' )
      h += "<div>max. iterations " + numInput( base.concat( [ 'params', 'max_iterations' ] ), st.params.max_iterations ) + "</div>";
    if ( isMc( st.method ) )
      h += "<div>MC iterations " + numInput( base.concat( [ 'params', 'mc_iterations' ] ), st.params.mc_iterations ) + "</div>";
    if ( family( st.method ) === '2dsa' )
      h += "<div>grid " + gridSelect( base.concat( [ 'params', 'grid' ] ), st.params.grid, true ) + "</div>";
    return h;
  }

  function renderStages() {
    var ids = state.stages.map( function ( s ) { return s.id; } );
    var h = "<fieldset><legend>Stages of every dataset</legend>" +
            "<p class='hint'>A stage starts when all stages it waits for have finished. Stages that do not wait " +
            "for each other run in parallel. Every stage must run after (or before) the 2DSA_IT stage.</p>" +
            "<table class='pipeline'><tr><th>On</th><th>Stage</th><th>Method</th><th>Waits for</th>" +
            "<th>Noise subtracted</th><th>Settings</th><th>More parameters</th><th></th></tr>";
    state.stages.forEach( function ( st, i ) {
      var base  = [ 'stages', i ];
      var after = ids.filter( function ( x ) { return x !== st.id; } ).map( function ( x ) {
        return "<label><input type='checkbox' data-path='" + p( base.concat( [ 'after' ] ) ) + "' data-type='listitem' value='" +
               esc( x ) + "'" + ( st.after.indexOf( x ) >= 0 ? " checked='checked'" : '' ) + " />" + esc( x ) + "</label>";
      } ).join( ' ' );
      var nopts = [ [ '', '(automatic)' ], [ 'queue_setup', 'as selected in queue setup' ], [ 'none', 'none' ] ]
                  .concat( ids.filter( function ( x ) { return x !== st.id; } ).map( function ( x ) { return [ x, 'from stage ' + x ]; } ) );
      h += "<tr><td><input type='checkbox' data-path='" + p( base.concat( [ 'enabled' ] ) ) + "' data-type='bool'" +
           ( st.enabled !== false ? " checked='checked'" : '' ) + " /></td>" +
           "<td><b>" + esc( st.id ) + "</b></td>" +
           "<td>" + select( base.concat( [ 'method' ] ), methodsOf( 'dataset' ).map( function ( m ) { return [ m, m ]; } ), st.method, 'method' ) + "</td>" +
           "<td>" + ( after || '-' ) + "</td>" +
           "<td>" + select( base.concat( [ 'noise_from' ] ), nopts, st.noise_from || '' ) + "</td>" +
           "<td>" + stageSettings( base, st ) + "</td>" +
           "<td>" + paramEditor( base.concat( [ 'params' ] ), [ 'noise_fit', 'max_iterations', 'mc_iterations' ] ) + "</td>" +
           "<td><a href='#' data-act='delstage' data-index='" + i + "'>remove</a></td></tr>";
    } );
    h += "</table><p><select data-act='addstage'><option value=''>add stage&hellip;</option>" +
         methodsOf( 'dataset' ).map( function ( m ) { return "<option value='" + m + "'>" + m + "</option>"; } ).join( '' ) +
         "</select></p></fieldset>";
    return h;
  }

  function renderDatasets() {
    var h = "<fieldset><legend>Datasets</legend>" +
            "<p class='hint'>Untick a stage to leave it out for one dataset. Overrides apply to this dataset only " +
            "(all its stages, or one stage); band volume, simulation points and grids are also used in global fits.</p>" +
            "<table class='pipeline'><tr><th>Dataset</th><th>Data</th>";
    state.stages.forEach( function ( st ) { h += "<th>" + esc( st.id ) + "</th>"; } );
    h += "<th>Overrides</th></tr>";
    C.requests.forEach( function ( r ) {
      var ds = state.datasets[ r.key ] || {};
      h += "<tr><td><b>" + esc( r.key ) + "</b></td><td class='hint'>" + esc( r.filename ) + "<br/>" + esc( r.edit ) +
           "<br/>" + r.noises + " noise(s)</td>";
      state.stages.forEach( function ( st ) {
        var ov  = ds.stages && ds.stages[ st.id ] && ds.stages[ st.id ].enabled !== undefined ? ds.stages[ st.id ].enabled : undefined;
        var on  = ov !== undefined ? ov : st.enabled !== false;
        h += "<td><input type='checkbox' data-act='dsenable' data-key='" + esc( r.key ) + "' data-stage='" + esc( st.id ) + "'" +
             ( on ? " checked='checked'" : '' ) + " /></td>";
      } );
      h += "<td>all stages: " + paramEditor( [ 'datasets', r.key, 'params' ], [] );
      state.stages.forEach( function ( st ) {
        var sp = ds.stages && ds.stages[ st.id ] && ds.stages[ st.id ].params;
        if ( sp && Object.keys( sp ).length )
          h += "stage " + esc( st.id ) + ": " + paramEditor( [ 'datasets', r.key, 'stages', st.id, 'params' ], [] );
      } );
      h += "<select data-act='dsstageparams' data-key='" + esc( r.key ) + "'><option value=''>override for one stage&hellip;</option>" +
           state.stages.map( function ( st ) { return "<option value='" + esc( st.id ) + "'>" + esc( st.id ) + "</option>"; } ).join( '' ) +
           "</select></td></tr>";
    } );
    return h + "</table></fieldset>";
  }

  function renderGroups() {
    var dsIds = state.stages.map( function ( s ) { return s.id; } );
    var h = "<fieldset><legend>Global fits over groups of datasets</legend>" +
            "<p class='hint'>A global stage runs one fit over all datasets of the group, after the stages it waits " +
            "for have finished for every member. Each member needs a 2DSA_IT stage first. Global fits do not fit noise.</p>";
    state.groups.forEach( function ( g, gi ) {
      var gb = [ 'groups', gi ];
      var gids = g.stages.map( function ( s ) { return s.id; } );
      h += "<div class='group'><b>Group</b> <input type='text' data-path='" + p( gb.concat( [ 'name' ] ) ) + "' data-type='str' value='" +
           esc( g.name ) + "' /> <a href='#' data-act='delgroup' data-index='" + gi + "'>remove group</a>" +
           "<div>Members: " + C.requests.map( function ( r ) {
             return "<label><input type='checkbox' data-path='" + p( gb.concat( [ 'members' ] ) ) + "' data-type='listitem' value='" +
                    esc( r.key ) + "'" + ( g.members.indexOf( r.key ) >= 0 ? " checked='checked'" : '' ) + " />" + esc( r.key ) + "</label>";
           } ).join( ' ' ) + "</div>" +
           "<div>Group parameters (fit-wide): " + paramEditor( gb.concat( [ 'params' ] ), [ 'noise_fit' ] ) + "</div>" +
           "<table class='pipeline'><tr><th>On</th><th>Stage</th><th>Method</th><th>Waits for (members)</th>" +
           "<th>Waits for (group)</th><th>Noise subtracted</th><th>Settings</th><th>More parameters</th><th></th></tr>";
      g.stages.forEach( function ( st, si ) {
        var b = gb.concat( [ 'stages', si ] );
        var am = dsIds.map( function ( x ) {
          return "<label><input type='checkbox' data-path='" + p( b.concat( [ 'after_member' ] ) ) + "' data-type='listitem' value='" +
                 esc( x ) + "'" + ( st.after_member.indexOf( x ) >= 0 ? " checked='checked'" : '' ) + " />" + esc( x ) + "</label>";
        } ).join( ' ' );
        var ga = gids.filter( function ( x ) { return x !== st.id; } ).map( function ( x ) {
          return "<label><input type='checkbox' data-path='" + p( b.concat( [ 'after' ] ) ) + "' data-type='listitem' value='" +
                 esc( x ) + "'" + ( st.after.indexOf( x ) >= 0 ? " checked='checked'" : '' ) + " />" + esc( x ) + "</label>";
        } ).join( ' ' );
        var nopts = [ [ '', '(automatic)' ], [ 'queue_setup', 'as selected in queue setup' ], [ 'none', 'none' ] ]
                    .concat( st.after_member.map( function ( x ) { return [ 'member:' + x, "from members' stage " + x ]; } ) );
        h += "<tr><td><input type='checkbox' data-path='" + p( b.concat( [ 'enabled' ] ) ) + "' data-type='bool'" +
             ( st.enabled !== false ? " checked='checked'" : '' ) + " /></td>" +
             "<td><b>" + esc( st.id ) + "</b></td>" +
             "<td>" + select( b.concat( [ 'method' ] ), methodsOf( 'group' ).map( function ( m ) { return [ m, m ]; } ), st.method, 'method' ) + "</td>" +
             "<td>" + ( am || '-' ) + "</td><td>" + ( ga || '-' ) + "</td>" +
             "<td>" + select( b.concat( [ 'noise_from' ] ), nopts, st.noise_from || '' ) + "</td>" +
             "<td>" + stageSettings( b, st ) + "</td>" +
             "<td>" + paramEditor( b.concat( [ 'params' ] ), [ 'noise_fit', 'max_iterations', 'mc_iterations' ] ) + "</td>" +
             "<td><a href='#' data-act='delgstage' data-group='" + gi + "' data-index='" + si + "'>remove</a></td></tr>";
      } );
      h += "</table><select data-act='addgstage' data-group='" + gi + "'><option value=''>add global stage&hellip;</option>" +
           methodsOf( 'group' ).map( function ( m ) { return "<option value='" + m + "'>" + m + "</option>"; } ).join( '' ) +
           "</select></div>";
    } );
    return h + "<p><input type='button' data-act='addgroup' value='Add group' /></p></fieldset>";
  }

  function render() {
    root.innerHTML = renderShared() + renderStages() + renderDatasets() + renderGroups();
  }

  // ------------------------------------------------------------ editing

  function removeStageRefs( id ) {
    state.stages.forEach( function ( st ) {
      st.after = st.after.filter( function ( x ) { return x !== id; } );
      if ( st.noise_from === id ) delete st.noise_from;
    } );
    Object.keys( state.datasets ).forEach( function ( k ) {
      if ( state.datasets[ k ].stages ) delete state.datasets[ k ].stages[ id ];
    } );
    state.groups.forEach( function ( g ) {
      g.stages.forEach( function ( st ) {
        st.after_member = st.after_member.filter( function ( x ) { return x !== id; } );
        if ( st.noise_from === 'member:' + id ) delete st.noise_from;
      } );
    } );
  }

  function onChange( ev ) {
    var t = ev.target;
    var act = t.getAttribute( 'data-act' );
    if ( act === 'addparam' ) {
      // the new parameter is shown with an empty value until the user types one
      if ( t.value ) {
        var path = JSON.parse( t.getAttribute( 'data-path' ) ).concat( [ t.value ] );
        if ( getPath( path ) === undefined ) setPath( path, '' );
      }
      render();
      return;
    }
    if ( act === 'addstage' && t.value ) {
      var id = newStageId( state.stages.map( function ( s ) { return s.id; } ) );
      var it = state.stages.filter( function ( s ) { return s.method === '2DSA_IT'; } )[ 0 ];
      var st = { id: id, method: t.value, enabled: true, after: it && t.value !== '2DSA_IT' ? [ it.id ] : [], params: {} };
      if ( isMc( t.value ) ) st.params.mc_iterations = 100;
      state.stages.push( st );
      render();
      return;
    }
    if ( act === 'addgstage' && t.value ) {
      var g = state.groups[ +t.getAttribute( 'data-group' ) ];
      var gid = newStageId( g.stages.map( function ( s ) { return s.id; } ) );
      var its = state.stages.filter( function ( s ) { return s.method === '2DSA_IT'; } ).map( function ( s ) { return s.id; } );
      var gst = { id: gid, method: t.value, enabled: true, after_member: its, after: [], params: {} };
      if ( isMc( t.value ) ) gst.params.mc_iterations = 50;
      g.stages.push( gst );
      render();
      return;
    }
    if ( act === 'dsenable' ) {
      var key = t.getAttribute( 'data-key' ), sid = t.getAttribute( 'data-stage' );
      var tmpl = state.stages.filter( function ( s ) { return s.id === sid; } )[ 0 ];
      var path2 = [ 'datasets', key, 'stages', sid, 'enabled' ];
      setPath( path2, t.checked === ( tmpl.enabled !== false ) ? undefined : t.checked );
      return;
    }
    if ( act === 'dsstageparams' && t.value ) {
      setPath( [ 'datasets', t.getAttribute( 'data-key' ), 'stages', t.value, 'params' ],
               getPath( [ 'datasets', t.getAttribute( 'data-key' ), 'stages', t.value, 'params' ] ) || {} );
      var sel = getPath( [ 'datasets', t.getAttribute( 'data-key' ), 'stages', t.value, 'params' ] );
      if ( !Object.keys( sel ).length ) sel[ 'band_volume-value' ] = '';
      render();
      return;
    }
    var ps = t.getAttribute( 'data-path' );
    if ( !ps ) return;
    var pth  = JSON.parse( ps );
    var type = t.getAttribute( 'data-type' );
    if ( type === 'bool' ) setPath( pth, t.checked );
    else if ( type === 'listitem' ) {
      var list = getPath( pth ) || [];
      list = list.filter( function ( x ) { return x !== t.value; } );
      if ( t.checked ) list.push( t.value );
      setPath( pth, list );
      render();
    }
    else if ( type === 'grid' ) {
      var v = t.value;
      setPath( pth, v === '' ? undefined : ( v === 'uniform' ? { type: 'uniform' } : { type: 'custom', modelID: Number( v.split( ':' )[ 1 ] ) } ) );
    }
    else if ( type === 'method' ) {
      setPath( pth, t.value );
      render();
    }
    else if ( type === 'val' ) {
      var val = parseValue( t.value );
      // keep added-but-empty override fields visible; empty shared fields fall back to defaults
      setPath( pth, val === undefined && pth[ 0 ] !== 'params' ? '' : val );
    }
    else setPath( pth, t.value === '' ? undefined : t.value );
  }

  function onClick( ev ) {
    var t = ev.target;
    var act = t.getAttribute( 'data-act' );
    if ( !act || t.tagName === 'SELECT' || t.type === 'checkbox' ) return;
    if ( act === 'delparam' ) {
      ev.preventDefault();
      setPath( JSON.parse( t.getAttribute( 'data-path' ) ), undefined );
      render();
    } else if ( act === 'delstage' ) {
      ev.preventDefault();
      var removed = state.stages.splice( +t.getAttribute( 'data-index' ), 1 )[ 0 ];
      removeStageRefs( removed.id );
      render();
    } else if ( act === 'delgstage' ) {
      ev.preventDefault();
      var g = state.groups[ +t.getAttribute( 'data-group' ) ];
      var r = g.stages.splice( +t.getAttribute( 'data-index' ), 1 )[ 0 ];
      g.stages.forEach( function ( st ) { st.after = st.after.filter( function ( x ) { return x !== r.id; } ); } );
      render();
    } else if ( act === 'delgroup' ) {
      ev.preventDefault();
      state.groups.splice( +t.getAttribute( 'data-index' ), 1 );
      render();
    } else if ( act === 'addgroup' ) {
      var names = state.groups.map( function ( x ) { return x.name; } );
      var n = 1;
      while ( names.indexOf( 'G' + n ) >= 0 ) n++;
      var its = state.stages.filter( function ( s ) { return s.method === '2DSA_IT'; } ).map( function ( s ) { return s.id; } );
      state.groups.push( { name: 'G' + n, members: C.requests.map( function ( r ) { return r.key; } ), params: {},
                           stages: [ { id: 's1', method: '2DSA_GL_IT', enabled: true, after_member: its, after: [], params: {} } ] } );
      render();
    }
  }

  // empty override values are not valid parameters: drop them before sending
  function cleaned() {
    var s = JSON.parse( JSON.stringify( state ) );
    function prune( o ) {
      if ( !o || typeof o !== 'object' ) return;
      Object.keys( o ).forEach( function ( k ) {
        if ( o[ k ] === '' ) delete o[ k ]; else prune( o[ k ] );
      } );
    }
    prune( s.params ); prune( s.datasets );
    s.stages.forEach( function ( st ) { prune( st.params ); } );
    s.groups.forEach( function ( g ) { prune( g.params ); g.stages.forEach( function ( st ) { prune( st.params ); } ); } );
    return s;
  }

  root.addEventListener( 'change', onChange );
  root.addEventListener( 'click', onClick );
  document.getElementById( 'pipeline_form' ).addEventListener( 'submit', function () {
    document.getElementById( 'spec_json' ).value = JSON.stringify( cleaned() );
  } );
  render();
} )();
