function toggleGames(source) {
  checkboxes = document.getElementsByName('items[]');
  for(var i=0, n=checkboxes.length;i<n;i++) {
    checkboxes[i].checked = source.checked;
  }
}

function loadGames() {

  // Get the cookies
  var page=parseInt( getCookie( "gl_page"));
  var sort=getCookie( "gl_sort");

  // Parse the game selection tags
  var tags_str = parseTags( 'form-tags');

  console.log( tags_str);

  // Fetch games data from the DB
  $.getJSON( URI_arr.join("/") + '/loadGames',
    'tags=' + JSON.stringify( encodeURIComponent( tags_str))+'&page='+page+'&sort='+sort, function(data) {

    // Build a table, start with header
    var items = [];
    items.push('<tr class="tableHeader"><td><input type="checkbox" id="checkAll" onClick="toggleGames(this)"/></td>' +
        '<td><abbr title="Avaliable analysis for White">A</abbr></td><td>White</td><td>ELO</td>' +
	'<td><abbr title="Avaliable analysis for Black">A</abbr></td><td>Black</td><td>ELO</td>' +
	'<td style="text-align:center">Result</td><td>ECO</td><td>Event</td>' +
        '<td><a href="' + window.location.pathname +
        '#" onclick="setCookie(\'gl_sort\',\'Date\',1);loadGames();" style="text-decoration: none;">&#x2191;</a>&nbsp;Date&nbsp;' +
        '<a href="' + window.location.pathname +
        '#" onclick="setCookie(\'gl_sort\',\'DateDesc\',1);loadGames();" style="text-decoration: none;">&#x2193;</a></td>'+
        '<td><a href="' + window.location.pathname +
        '#" onclick="setCookie(\'gl_sort\',\'Moves\',1);loadGames();" style="text-decoration: none;">&#x2191;</a>&nbsp;<abbr title="Number of game moves">M</abbr>&nbsp;'+
        '<a href="' + window.location.pathname +
        '#" onclick="setCookie(\'gl_sort\',\'MovesDesc\',1);loadGames();" style="text-decoration: none;">&#x2193;</a></td>'+
        '<td></td></tr>');

    // Iterate through all the loaded games
    $.each(data, function(key, val) {

      var W_analysis_icon = '';
      var B_analysis_icon = '';
      if( typeof val["Analysis_W"] == 'string' && val["Analysis_W"].length > 0)
	W_analysis_icon = '<a href="#" onclick="showQueueTag( ' + val["ID"] + ')"><img src="img/' +
	val["Analysis_W"] + '.png" title="' + val["Analysis_W"] + ' analysis present"/></a>';
      if( typeof val["Analysis_B"] == 'string' && val["Analysis_B"].length > 0)
	B_analysis_icon = '<a href="#" onclick="showQueueTag( ' + val["ID"] + ')"><img src="img/' +
	val["Analysis_B"] + '.png" title="' + val["Analysis_B"] + ' analysis present"/></a>';

      items.push('<tr class="tableRow"><td class="centered"><input type="checkbox" value="' + val["ID"] + '" name="items[]"/></td>' +
	'<td class="centered">' + W_analysis_icon + '</td>' +
	'<td>' + val["White"] + '</td><td class="centered">' + val["ELO_W"] + '</td>' +
	'<td class="centered">' + B_analysis_icon + '</td>' +
	'<td>' + val["Black"] + '</td><td class="centered">' + val["ELO_B"] + '</td>' +
	'<td class="centered">' + val["Result"] + '</td><td class="centered">' + val["ECO"] +
	"</td><td style='width:175px;'>" + val["Event"] + "</td><td>" + val["Date"] +
        '<td class="centered">' + val["Moves"] +
        '</td><!--<td class="centered">' + val["W_cheat_score"] +
        '</td><td class="centered">' + colorScore( val["W_cheat_score"]-val["White_ELO"]) +
        '</td><td class="centered">' + val["B_cheat_score"] +
        '</td><td class="centered">' + colorScore( val["B_cheat_score"]-val["Black_ELO"]) +
        '</td>--><td><a href="#" onclick="showGameDetails( ' + val["ID"] +
	')"><img src="img/analysis.png" width="16px" title="Show game analysis"/></a></td></tr>');
    });

    // Clear existing table, display new data
    $('.gameList').empty();
    $('<table/>', {
      'class': 'my-new-table',
      html: items.join('')
    }).appendTo( document.getElementById('gameList'));
  });
}

// A selection of games have been deleted
function deleteGamesList() {

  var gids = [];
  $("input[name='items[]']:checked").each(function () {
    gids.push( $(this).val());
  });

  console.log( "Game ids to delete: " + JSON.stringify( gids));

  $.post( "deleteGamesList", { gids: JSON.stringify( gids)},
    function(result) { document.getElementById('gamesActionStatus').innerHTML = result; });
}
