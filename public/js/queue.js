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
    items.push('<tr class="tableHeader">' +
        '<td style="text-align:center"><a href="' + window.location.pathname +
        '#" onclick="setCookie(\'qa_sort\',\'Place\',1);loadQueue();" style="text-decoration: none;">&#x2191;</a>&nbsp;#&nbsp;' +
        '<a href="' + window.location.pathname +
        '#" onclick="setCookie(\'qa_sort\',\'PlaceDesc\',1);loadQueue();" style="text-decoration: none;">&#x2193;</a></td>'+
        '<td>Status</td><td>Game</td><td style="text-align:center">Side</td><td>Depth</td><td>Estimated</td>' +
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
      default:
        status_image="complete";
        status_descr="Game analysis is complete";
    }

    items.push('<tr class="tableRow"><td style="text-align:center">' + val["Index"] +
        '</td><td style="text-align:center"><img src="img/' + status_image + '.png" title="' + status_descr + '"/>' +
        '</td><td>' + val["White"] + ' vs. ' + val["Black"] + ' - ' + val["Result"] + ', ' + val["ECO"] + ', ' + val["Date"] +
        '</td><td>' + val["Side"] + '</td><td>' + val["Depth"] + '</td><td>' + val["Interval"] +
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
