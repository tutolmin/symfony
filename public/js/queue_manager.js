function toggleQueue(source) {
  checkboxes = document.getElementsByName('queue_items[]');
  for(var i=0, n=checkboxes.length;i<n;i++) {
    checkboxes[i].checked = source.checked;
  }
}

function loadQueue() {

  // Get the cookies
  var page=parseInt( getCookie( "qa_page"));
  var sort=getCookie( "qa_sort");

  // Parse the game selection tags
  var tags_str = parseTags( 'queue-form-tags');

  console.log( tags_str);

  // Fetch games data from the DB
  $.getJSON( URI_arr.join("/") + '/loadQueue',
    'tags=' + JSON.stringify( encodeURIComponent( tags_str))+'&page='+page+'&sort='+sort, function(data) {

    // Build a table, start with header
    var items = [];
    items.push('<tr class="tableHeader"><td><input type="checkbox" id="checkAll" onClick="toggleQueue(this)"/></td>' +
        '<td style="text-align:center"><a href="' + window.location.pathname +
        '#" onclick="setCookie(\'qa_sort\',\'Place\',1);loadQueue();" style="text-decoration: none;">&#x2191;</a>&nbsp;#&nbsp;' +
        '<a href="' + window.location.pathname +
        '#" onclick="setCookie(\'qa_sort\',\'PlaceDesc\',1);loadQueue();" style="text-decoration: none;">&#x2193;</a></td>'+
        '<td>H</td><td>Status' +
'<br/><select name="AnalysisStatusManager" id="AnalysisStatusManager" onchange="setAnalysisParamList(this, \'status\')">' +
'<option value="">Select</option>' +
'<option value="Pending">Pending</option>' +
'<option value="Evaluated">Evaluated</option>' +
'<option value="Skipped">Skipped</option>' +
'<option value="Partially">Partially</option>' +
'<option value="Exported">Exported</option>' +
'<option value="Complete">Complete</option>' +
'</select></td><td>Game</td><td style="text-align:center">Side' +
'<br/><select name="AnalysisSideManager" id="AnalysisSideManager" onchange="setAnalysisParamList(this, \'side\')">' +
'<option value="">Select</option>' +
'<option value="">Both sides</option>' +
'<option value="WhiteSide">White Only</option>' +
'<option value="BlackSide">Black Only</option>' +
'</select></td><td>Type' +
'<br/><select name="AnalysisDepthManager" id="AnalysisDepthManager" onchange="setAnalysisParamList(this, \'depth\')">' +
'<option value="">Select</option>' +
'<option value="fast">Fast</option>' +
'<option value="deep">Deep</option>' +
'</select>' +
'</td><td>Estimated</td>' +
        '<td></td></tr>');

    // Iterate through all the loaded games
    $.each(data, function(key, val) {

    var status_image="pending";
    var status_descr="Game is pending for processing";

    switch( val["Status"]) {
      case "Paid":
        status_image="paid";
        status_descr="Priority processing is pending";
        break;
      case "Pending":
        status_image="pending";
        status_descr="Game is pending for processing";
        break;
      case "Processing":
        status_image="processing";
        status_descr="Game is being processed at the moment";
        break;
      case "Skipped":
        status_image="skipped";
        status_descr="Game analysis has been skipped";
        break;
      case "Partially":
        status_image="partially";
        status_descr="Game analysis is partially complete";
        break;
      case "Evaluated":
        status_image="processing";
        status_descr="Game moves have been evaluated";
        break;
      case "Exported":
        status_image="processing";
        status_descr="Game moves have been exported for validation";
        break;
      default:
        status_image="complete";
        status_descr="Game analysis is complete";
    }

	white_elo = '';
	black_elo = '';
	if( val["ELO_W"] != "") white_elo = ' (' + val["ELO_W"] + ') ';
	if( val["ELO_B"] != "") black_elo = ' (' + val["ELO_B"] + ') ';

	action_rows = '';
	val["Actions"].forEach(function(item, i, arr) {
	  action_rows += '<tr><td>' + val["ADateTimes"][i] + '</td><td>' + item + '</td><td>' + val["AParams"][i] + '</td></tr>';
	});

    items.push('<tr class="tableRow"><td><input type="checkbox" value="' + val["AId"] + '" name="queue_items[]"/>' +
	'</td><td style="text-align:center">' + val["Index"] +
        '</td><td style="text-align:center"><div class="tooltip"><img src="img/actions.png"/>' +
	'<span class="tooltiptext"><table>' + action_rows + '</table></span></div>' +
        '</td><td style="text-align:center"><img src="img/' + status_image + '.png" title="' + status_descr + '"/>' +
        '</td><td>' + val["White"] + white_elo + ' vs. ' + val["Black"] + black_elo +
	' - ' + val["Result"] + ', ' + val["ECO"] + ', ' + val["Date"] +
        '</td><td>' +
'<select name="AnalysisSide" id="AnalysisSide" onchange="setAnalysisParam(this, \'side\', ' + val["AId"] + ')">' +
'<option value=""' + ((val["Side"]=="Both")?'selected="selected"':'') + '>Both sides</option>' +
'<option value="WhiteSide"' + ((val["Side"]=="White")?'selected="selected"':'') + '>White Only</option>' +
'<option value="BlackSide"' + ((val["Side"]=="Black")?'selected="selected"':'') + '>Black Only</option>' +
'</select>' +
	'</td><td>' +
'<select name="AnalysisDepth" id="AnalysisDepth" onchange="setAnalysisParam(this, \'depth\', ' + val["AId"] + ')">' +
'<option value="fast"' + ((val["Depth"]<20)?'selected="selected"':'') + '>Fast</option>' +
'<option value="deep"' + ((val["Depth"]>20)?'selected="selected"':'') + '>Deep</option>' +
'</select>' +
	'</td><td>' + val["Interval"] +
        '</td><td><a href="#" onclick="showGameDetails( \'' + val["Hash"] +
        '\')"><img src="img/analysis.png" width="16px" title="Show game analysis"/></a></td></td></tr>');
    });

    // Clear existing table, display new data
    $('.analysisQueue').empty();
    $('<table/>', {
      'class': 'my-new-table',
      html: items.join('')
    }).appendTo( document.getElementById('analysisQueue'));
  });
}

// Change analysis parameter for a list of items
function setAnalysisParamList( selectObject, param) {

  var aids = [];
  $("input[name='queue_items[]']:checked").each(function () {
    aids.push( $(this).val());
  });
  var value = selectObject.value;

  console.log( "Analysis Ids to change " + param + " to  " + value + " : " + JSON.stringify( aids));

  $.post( "setAnalysisParam", { aids: JSON.stringify( aids), param: param, value: value},
    function(result) { document.getElementById('analysisActionStatus').innerHTML = result; });
}

// Change analysis parameter for a particular item
function setAnalysisParam( selectObject, param, aid) {

  var aids = [aid];
  var value = selectObject.value;

  console.log( "New " + param + ": " + value + " for analysis id: " + JSON.stringify( aids));

  $.post( "setAnalysisParam", { aids: JSON.stringify( aids), param: param, value: value},
    function(result) { document.getElementById('analysisActionStatus').innerHTML = result; });
}

// A selection of games have been deleted from the analysis queue
function deleteAnalysisList() {

  var aids = [];
  $("input[name='queue_items[]']:checked").each(function () {
    aids.push( $(this).val());
  });

  console.log( "Analysis Ids to delete: " + JSON.stringify( aids));

  $.post( "deleteAnalysisList", { aids: JSON.stringify( aids)},
    function(result) { document.getElementById('analysisActionStatus').innerHTML = result; });
}

// A selection of games have been submitted for promotion
function promoteAnalysisList() {

  var aids = [];
  $("input[name='queue_items[]']:checked").each(function () {
    aids.push( $(this).val());
  });

  console.log( "Analysis Ids to delete: " + JSON.stringify( aids));

  $.post( "promoteAnalysisList", { aids: JSON.stringify( aids)},
    function(result) { document.getElementById('analysisActionStatus').innerHTML = result; });
}
