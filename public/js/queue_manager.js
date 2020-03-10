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
    items.push('<tr class="tableHeader"><td><input type="checkbox" id="checkAll"/></td>' +
        '<td style="text-align:center"><a href="' + window.location.pathname +
        '#" onclick="setCookie(\'qa_sort\',\'Place\',1);loadQueue();" style="text-decoration: none;">&#x2191;</a>&nbsp;#&nbsp;' +
        '<a href="' + window.location.pathname +
        '#" onclick="setCookie(\'qa_sort\',\'PlaceDesc\',1);loadQueue();" style="text-decoration: none;">&#x2193;</a></td>'+
        '<td>Status</td><td>Game</td><td style="text-align:center">Side' +
'<br/><select name="AnalysisSide" id="AnalysisSide" onchange="setAnalysisParamList(this, \'side\')">' +
'<option value="">Select</option>' +
'<option value="">Both sides</option>' +
'<option value="WhiteSide">White Only</option>' +
'<option value="BlackSide">Black Only</option>' +
'</select></td><td>Depth' +
'<br/><select name="AnalysisDepth" id="AnalysisDepth" onchange="setAnalysisParamList(this, \'depth\')">' +
'<option value="">Select</option>' +
'<option value="">18+ plies</option>' +
'<option value="20">20+ plies</option>' +
'<option value="23">23+ plies</option>' +
'</select>' +
'</td><td>Scheduled</td>' +
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
      default:
        status_image="complete";
        status_descr="Game analysis is complete";
    }

    items.push('<tr class="tableRow"><td><input type="checkbox" value="' + val["AId"] + '" name="queue_items[]"/>' +
	'</td><td style="text-align:center">' + val["Index"] +
        '</td><td style="text-align:center"><img src="img/' + status_image + '.png" title="' + status_descr + '"/>' +
        '</td><td>' + val["White"] + ' vs. ' + val["Black"] + ' - ' + val["Result"] + ', ' + val["ECO"] + ', ' + val["Date"] +
        '</td><td>' + 
'<select name="AnalysisSide" id="AnalysisSide" onchange="setAnalysisParam(this, \'side\', ' + val["AId"] + ')">' +
'<option value=""' + ((val["Side"]=="Both")?'selected="selected"':'') + '>Both sides</option>' +
'<option value="WhiteSide"' + ((val["Side"]=="White")?'selected="selected"':'') + '>White Only</option>' +
'<option value="BlackSide"' + ((val["Side"]=="Black")?'selected="selected"':'') + '>Black Only</option>' +
'</select>' +
	'</td><td>' +
'<select name="AnalysisDepth" id="AnalysisDepth" onchange="setAnalysisParam(this, \'depht\', ' + val["AId"] + ')">' +
'<option value=""' + ((val["Depth"]==18)?'selected="selected"':'') + '>18+ plies</option>' +
'<option value="20"' + ((val["Depth"]==20)?'selected="selected"':'') + '>20+ plies</option>' +
'<option value="23"' + ((val["Depth"]==23)?'selected="selected"':'') + '>23+ plies</option>' +
'</select>' +
	'</td><td>' + val["Date"] +
        '</td><td><button onclick="showGameDetails(' + val["ID"] + ');">Analysis</button></td></td></tr>');
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
