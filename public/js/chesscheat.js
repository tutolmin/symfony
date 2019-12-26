function parse_url( url){
        // example 1: parse_url('http://example.com:3000/pathname/?search=test#hash');
        // returns 1: {protocol: 'http:', hostname: 'example.com', port: '3000', pathname: '/pathname/', search: '?search=test', hash: '#hash', host: 'example.com:3000'}
        
        var parser = document.createElement('a');
        parser.href = url;

        return parser;
}

// Get our URI location
parser = parse_url( document.URL);
URI_arr = parser.pathname.split( '/');
URI_arr.shift();
URI_arr.pop();
//console.log( parser + " " + URI_arr.join("/"));

//var Game = new Object();
var Positions;
var W_baselines, B_baselines;

function processGameList() {
  var s = document.getElementById("sideToAnalyzeGroup").value; 
//var checked = [];
$("input[name='items[]']:checked").each(function () {
//  checked.push($(this).val());
  $.post( "setGameStatus", { gid: $(this).val(), side: s} );
});
//  console.log( checked);
}

function processGame( gid) {
  var s = document.getElementById("sideToAnalyze").value; 
  console.log( "Game ID: " + gid + " side: " + s);
  $.post( "setGameStatus", { gid: gid, side: s} );
}

function showGameDetails( gid) {
  console.log( "Game ID: " + gid);
Game = new Object;

document.getElementById('header').innerHTML = "Loading...";
//document.getElementById('board').innerHTML = "Loading...";
document.getElementById('movelist').innerHTML = "Loading...";
document.getElementById('position').innerHTML = "Loading...";
document.getElementById('counters').innerHTML = "Loading...";
document.getElementById('analysis_submit').innerHTML = '<button type="submit" class="input_submit" style="margin-right: 15px;" onClick="processGame(' 
+gid+ ')">Submit for analysis </button>';

$.getJSON( URI_arr.join("/") + '/getGameDetails', 'gid='+gid, function(data){

  $.each(data, function(key, val){
        if( !val) {
                Game[key]=0;
        } else {
                Game[key]=val;
        }
  });
  Positions = Game["Positions"];
  W_baselines = Game["W_baselines"];
  B_baselines = Game["B_baselines"];
  init();
//  positionIndex=0;
  updateMovelist2();
});
document.getElementById("analysisTab").click();
}

function setCookie(cname, cvalue, exdays) {
    var d = new Date();
    d.setTime(d.getTime() + (exdays*24*60*60*1000));
    var expires = "expires="+ d.toUTCString();
    document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
}


function getCookie(cname) {
    var name = cname + "=";
    var decodedCookie = decodeURIComponent(document.cookie);
    var ca = decodedCookie.split(';');
    for(var i = 0; i <ca.length; i++) {
        var c = ca[i];
        while (c.charAt(0) == ' ') {
            c = c.substring(1);
        }
        if (c.indexOf(name) == 0) {
            return c.substring(name.length, c.length);
        }
    }
    return "";
}


function loadGames() {

var page=parseInt(getCookie( "page"));
var sort=getCookie( "sort");
//console.log( "Page from cookie: " + page);
//console.log( 'tags='+JSON.stringify( document.getElementById('form-tags').value)+'&page='+page);
//

// Parse the tags
var tags_arr = document.getElementById('form-tags').value.split(';');
var tags_str ="";

console.log( tags_arr);

// Iterate all tags
tags_arr.forEach(function(item, i, arr) {
  if( item.length>0) {

  // Game result has been specified
  if( item == "1-0" || item == "0-1" || item == "1/2-1/2") {
    tags_str += "result:" + item + ";";
  } else {

    // Has a color specification
    var re = /([\w,\.\ ]+)((\ |_)as(\ |_)(white|black))$/i;
    var found_color = item.match(re);
    if( found_color) tags_str += found_color[5].toLowerCase() + ":" + found_color[1] + ";";
//    console.log( found_color);

    // Has a result specification
    var re = /([\w,\.\ ]+)((\ |_)(wins|loses|draws))$/i;
    var found_result = item.match(re);
    if( found_result) tags_str += found_result[4].toLowerCase() + ":" + found_result[1] + ";";
//    console.log( found_result);

    // Simply numeric (game ID in the DB)
    var re = /(\d+)$/i;
    var found_result = item.match(re);
    if( found_result) tags_str += "id:" + found_result[1] + ";";
//    console.log( found_result);

    // Game ending type
    var re = /(stale)?mate$/i;
    var found_result = item.match(re);
    if( found_result) tags_str += "ending:" + found_result[0] + ";";
    console.log( found_result);

    // Just a player
    if( !found_color && !found_result) {
      tags_str += "player:" + item + ";";
    }
  }
  }
});

console.log( tags_str);

$.getJSON( URI_arr.join("/") + '/loadGames', 
'tags=' + JSON.stringify( encodeURIComponent( tags_str))+'&page='+page+'&sort='+sort, function(data){
//'tags=' + JSON.stringify( encodeURIComponent( document.getElementById('form-tags').value))+'&page='+page+'&sort='+sort, function(data){
  var items = [];
//onclick="$(\'.tagsinput#form-tags\').addTag( \'sortDate\');" 
  items.push('<tr class="tableHeader"><td colspan=2><input type="checkbox" id="select_all"/></td>' +
        '<td>White</td><td>Black</td><td style="text-align:center">Result</td><td>Event</td>' +
        '<td><a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'Date\',1);loadGames();" style="text-decoration: none;">&#x2193;</a>&nbsp;Date&nbsp;' +
        '<a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'DateDesc\',1);loadGames();" style="text-decoration: none;">&#x2191;</a></td>'+
        '<td><a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'Moves\',1);loadGames();" style="text-decoration: none;">&#x2193;</a>&nbsp;Moves&nbsp;'+
        '<a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'MovesDesc\',1);loadGames();" style="text-decoration: none;">&#x2191;</a></td>'+
        '<!-- <td><a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'ScoreW\',1);loadGames();" style="text-decoration: none;">&#x2193;</a>&nbsp;Score&nbsp;W&nbsp;'+
        '<a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'ScoreWDesc\',1);loadGames();" style="text-decoration: none;">&#x2191;</a></td>'+
        '<td><a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'DeltaW\',1);loadGames();" style="text-decoration: none;">&#x2193;</a>&nbsp;W&nbsp;ELO&nbsp;&#x0394&nbsp;'+
        '<a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'DeltaWDesc\',1);loadGames();" style="text-decoration: none;">&#x2191;</a></td>'+
        '<td><a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'ScoreB\',1);loadGames();" style="text-decoration: none;">&#x2193;</a>&nbsp;Score&nbsp;B&nbsp;'+
        '<a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'ScoreBDesc\',1);loadGames();" style="text-decoration: none;">&#x2191;</a></td>'+
        '<td><a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'DeltaB\',1);loadGames();" style="text-decoration: none;">&#x2193;</a>&nbsp;B&nbsp;ELO&nbsp;&#x0394&nbsp;'+
        '<a href="' + window.location.pathname + 
        '#" onclick="setCookie(\'sort\',\'DeltaBDesc\',1);loadGames();" style="text-decoration: none;">&#x2191;</a></td>'+
        '--><td></td></tr>');
  $.each(data, function(key, val){

    var status_image="loaded";
    var status_descr="Game has been loaded into the database";
    switch(val["Status"]) {
      case "0_Paid":
        status_image="paid";
        status_descr="Priority processing is pending";
        break;
      case "1_Pending":
        status_image="pending";
        status_descr="Game is pending for processing";
        break;
      case "2_Loaded":
        status_image="loaded";
        status_descr="Game has been loaded into the database";
        break;
      case "3_Partly":
        status_image="partly";
        status_descr="Game has been partially analyzed";
        break;
      case "5_Processing":
        status_image="processing";
        status_descr="Game is being processed at the moment";
        break;
      default:
        status_image="complete";
        status_descr="Game analysis is complete";
    }

    items.push('<tr class="tableRow"><td><input type="checkbox" value="' + val["ID"] + '" name="items[]"/></td><td><img src="img/' + status_image + 
        '.png" title="' + status_descr + '"/></td><td>' + val["White"] + "</td><td>" + val["Black"] + '</td><td class="centered">' + 
        val["Result"] + "</td><td>" + val["Event"] + "</td><td>" + val["Date"] + 
        '<td class="centered">' + val["Moves"] + 
        '</td><!--<td class="centered">' + val["W_cheat_score"] + 
        '</td><td class="centered">' + colorScore( val["W_cheat_score"]-val["White_ELO"]) + 
        '</td><td class="centered">' + val["B_cheat_score"] + 
        '</td><td class="centered">' + colorScore( val["B_cheat_score"]-val["Black_ELO"]) + 
        '</td>--><td><button onclick="showGameDetails(' + val["ID"] + ');">Analysis</button></td></td></tr>');
  });
    items.push('<tr><td colspan="5">' +
'<select style="float:left;" name="sideToAnalyzeGroup" id="sideToAnalyzeGroup">'+
'<option value="">Both sides</option>'+
'<option value="WhiteOnly">White Only</option>'+
'<option value="BlackOnly">Black Only</option>'+
'</select>'+
'<div id="analysis_submit_group">'+
     '<button type="submit"'+
             'class="input_submit"'+
             'style="margin-right: 15px;"'+
             'onClick="processGameList()">Submit games for analysis'+
     '</button>'+
'</div></td>'+
        '<td><button onclick="setCookie(\'page\',0,1);loadGames();">First</button></td>'+
        '<td><button onclick="setCookie(\'page\',' + (page-1) + ',1);loadGames();">Prev</button></td>'+
        '<td><button onclick="setCookie(\'page\',' + (page+1) + ',1);loadGames();">Next</button></td></tr>');

  $('.gameList').empty();
  $('<table/>', {
    'class': 'my-new-table',
    html: items.join('')
  }).appendTo( document.getElementById('gameList'));
});

}

    $(function() {
            $('#form-tags').tagsInput({
                    'unique': true,
                    'minChars': 3,
                    'maxChars': 50,
                    'limit': 5,
                    'delimiter': [';'],
                    'validationPattern': new RegExp('^[a-zA-Z0-9,\.\ \/_+-]+$'),
                    'onAddTag': function(input, value) {
                    },
                    'onRemoveTag': function(input, value) {
                    },
                    'onChange': function(input, value) {
                        setCookie('page',0,1);
                        loadGames();
                          },

    'autocomplete': {
      source: "searchHint",
      minLength: 3,
      select: function( event, ui ) {
        console.log( "Selected: " + ui.item.value);
      }
    }
            });
    });

//  'autocomplete_url':'http://dev.chesscheat.com/autocomplete.php',
//  'autocomplete':{selectFirst:true,width:'100px',autoFill:true},





function openTab(evt, tabName) {
    // Declare all variables
    var i, tabcontent, tablinks;

    // Get all elements with class="tabcontent" and hide them
    tabcontent = document.getElementsByClassName("tabcontent");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }

    // Get all elements with class="tablinks" and remove the class "active"
    tablinks = document.getElementsByClassName("tablinks");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }

    // Show the current tab, and add an "active" class to the button that opened the tab
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.className += " active";
} 

// Get the element with id="defaultTab" and click on it
document.getElementById("defaultTab").click();

var positionIndex=0;
var variationIndex=-1;
var neo4j_root_FEN="rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1";


var onMoveEnd = function() {

var boardEl = $('#board'),
  game = new Chess(),
  squareClass = 'square-55d63';

//  console.log( positionIndex + ' ' + variationIndex);

  // Start button was clicked
  if( positionIndex == 0 ) {

    // Remove all highligths
    boardEl.find('.' + squareClass).removeClass('highlight-white');
    boardEl.find('.' + squareClass).removeClass('highlight-black');
    boardEl.find('.' + squareClass).removeClass('highlight-best');

  } else {

  // Get current/best move LALG and FEN
  current_FEN   = Positions[positionIndex-1][_FEN];
  current_move  = Positions[positionIndex][_MOVE];
  best_move     = null;
  if( Positions[positionIndex][_T1_MOVE] && variationIndex == 0)
    best_move = Positions[positionIndex][_T1_MOVE][0];

  // Variation move/FEN
  if( variationIndex > 0) {
    current_FEN = Positions[positionIndex+1][_T1_FEN][variationIndex-1];
    current_move= Positions[positionIndex+1][_T1_MOVE][variationIndex-1];
  }
/*
  // Get move Long Algebraic Notation
  game.load( current_FEN);
  var move = game.move( current_move, {sloppy: true});
//  console.log( current_move);

  // Get best move LALG
  game.load( current_FEN);
  var bmove = null;
  if( best_move) 
    bmove = game.move( best_move, {sloppy: true});
//  console.log( best_move);

  // Set/Remove highlights
  boardEl.find('.' + squareClass).removeClass('highlight-white');
  boardEl.find('.' + squareClass).removeClass('highlight-black');
  boardEl.find('.' + squareClass).removeClass('highlight-best');
  if (move.color === 'w') {
    boardEl.find('.square-' + move.from).addClass('highlight-white');
    boardEl.find('.square-' + move.to).addClass('highlight-white');
  }
  else {
    boardEl.find('.square-' + move.from).addClass('highlight-black');
    boardEl.find('.square-' + move.to).addClass('highlight-black');
  }
  if( bmove) {
    boardEl.find('.square-' + bmove.from).addClass('highlight-best');
    boardEl.find('.square-' + bmove.to).addClass('highlight-best');
  }
*/
  }
};

//--- start example JS ---
var currentGame = new Chess();
var cfg = {
  showNotation: true,
  position: 'start',
  onMoveEnd: onMoveEnd
};
var board = ChessBoard('board', cfg);

const _MOVE     = 0;
const _FEN      = 1;
const _ZKEY     = 2;
const _ECO      = 1;
const _OPENING  = 2;
const _VARIATION= 3;
const _EVAL     = 6;
const _MARK     = 7;
const _SCORE    = 8;
const _DEPTH    = 9;
const _TIME     = 10;
const _T1_MOVE  = 11;
const _T1_FEN   = 12;
const _T1_ZKEY  = 13;
const _T1_SCORE = 14;
const _T1_DEPTH = 15;
const _T1_TIME  = 16;

// New game has been loaded for analysis
var init = function() {

positionIndex=0;
variationIndex=0;
currentGame.reset();
board.position( currentGame.fen());

// Replay the game and convert LAN to SAN
for (index = 1; index < Positions.length; index++) {
  var cmove = currentGame.move( Positions[index][_MOVE], {sloppy: true});
  Positions[index][_MOVE] = cmove.san;
//  console.log( Positions[index][_MOVE]);
}
currentGame.reset();

/*
// Reinitializing for the new game load
cfg = {
  showNotation: true,
  position: 'start',
  onMoveEnd: onMoveEnd
};
board = ChessBoard('board', cfg);

// Show initial movelist
updateMovelist2();
*/

// Show game header
var WhiteELO = "";
if( Game['W_ELO'] != "" && Game['W_ELO'] != 0) { WhiteELO = " (" + Game['W_ELO'] + ")";}
var BlackELO = "";
if( Game['B_ELO'] != "" && Game['B_ELO'] != 0) { BlackELO = " (" + Game['B_ELO'] + ")";}
var ECO_opening_variation ="";
if( Game['ECO'] != "") { ECO_opening_variation = Game['ECO'];}
if( Game['ECO_opening'] != "") { ECO_opening_variation += ": " + Game['ECO_opening'];}
if( Game['ECO_variation'] != "") { ECO_opening_variation += ", " + Game['ECO_variation'];}
var gameDetails = "<h2>" + Game['White'] + WhiteELO
 + " vs. " + Game['Black'] + BlackELO + "</h2>"
 + "<h3>" + ECO_opening_variation + "</h3>"
 + Game['Event'] + ", " + Game['Date'] + ", " + Game['Result'];
if( Game['eResult'] != "" && Game['eResult'] != Game['Result']) { 
  gameDetails += " (effectively " + Game['eResult'] + ")"; }
document.getElementById('header').innerHTML = gameDetails;

// Show game details
var countersTable = "<table border=0 cellspacing=0 cellpadding=0 class='gameInfo'>";

for( const prefix of ["W_", "B_"]) { 

countersTable += "<tr><td>" +
"<table>" +
"<tr><th>" + ((prefix=="W_")?Game['White']:Game['Black']) + "</th></tr>" +
"<tr><td>Games</td></tr>" +
"<tr><td><abbr title='Total number of moves per game'>Plies</abbr></td></tr>" +
"<tr><td><abbr title='Number of analyzed moves'>Analyzed</abbr></td></tr>" +
"<tr><td><abbr title='Encyclopedia of Chess Openings'>ECO</abbr>, %</td></tr>" +
"<tr><td><abbr title='Top 1 moves'>T1</abbr>, %</td></tr>" +
"<tr><td><abbr title='Top 2 moves'>T2</abbr>, %</td></tr>" +
"<tr><td><abbr title='Top 3 moves'>T3</abbr>, %</td></tr>" +
"<tr><td><abbr title='ECO+T3 moves'>ET3</abbr>, %</td></tr>" +
"<tr><td><abbr title='Best moves'>Best</abbr>, %</td></tr>" +
"<tr><td><abbr title='Sound moves'>Sound</abbr>, %</td></tr>" +
"<tr><td><abbr title='Forced moves'>Forced</abbr>, %</td></tr>" +
"<tr><td><abbr title='Total number of move with evaluation differences with T1'>Deltas</abbr></td></tr>" +
"<tr><td><abbr title='Average Difference'>A.&nbsp;D.</abbr>,&nbsp;<abbr title='Centipawns (100 cp = 1 pawn)'>cp</abbr></td></tr>" +
"<tr><td><abbr title='Median Error'>Median</abbr>,&nbsp;<abbr title='Centipawns (100 cp = 1 pawn)'>cp</abbr></td></tr>" +
"<tr><td><abbr title='Standard Deviation'>S.&nbsp;D.</abbr>,&nbsp;<abbr title='Centipawns (100 cp = 1 pawn)'>cp</abbr></td></tr>" +
"<tr><td><abbr title='Calculated ELO score'>cELO</abbr></td></tr>" +
"<tr><td><abbr title='Distance to baseline'>bDist</abbr></td></tr>" +
"</table>" +
"</td>";

countersTable += "<td>" +
"<table>" +
"<tr><th>Game</th></tr>" +
"<tr><td>1</td></tr>" +
"<tr><td>" + Game[prefix+'Plies'] + "</td></tr>" +
"<tr><td>" + Game[prefix+'Analyzed'] + "</td></tr>" +
"<tr><td>" + Game[prefix+'ECO_rate'].toFixed(0) + "</td></tr>" +
"<tr><td>" + Game[prefix+'T1_rate'].toFixed(0) + "</td></tr>" +
"<tr><td>" + Game[prefix+'T2_rate'].toFixed(0) + "</td></tr>" +
"<tr><td>" + Game[prefix+'T3_rate'].toFixed(0) + "</td></tr>" +
"<tr><td>" + Game[prefix+'ET3_rate'].toFixed(0) + "</td></tr>" +
"<tr><td>" + Game[prefix+'Best_rate'].toFixed(0) + "</td></tr>" +
"<tr><td>" + Game[prefix+'Sound_rate'].toFixed(0) + "</td></tr>" +
"<tr><td>" + Game[prefix+'Forced_rate'].toFixed(0) + "</td></tr>" +
"<tr><td>" + Game[prefix+'Deltas'] + "</td></tr>" +
"<tr><td>" + Game[prefix+'avg_diff'].toFixed(1) + "</td></tr>" +
"<tr><td>" + Game[prefix+'median'].toFixed(1) + "</td></tr>" +
"<tr><td>" + Game[prefix+'std_dev'].toFixed(1) + "</td></tr>" +
"<tr><td>" + Game[prefix+'cheat_score'].toFixed(0) + "</td></tr>" +
"<tr><td>" + Game[prefix+'perp_len'].toFixed(1) + "</td></tr>" +
"</table>" +
"</td>";

for( const baseline of ((prefix=="W_")?W_baselines:B_baselines)) { 

countersTable += "<td>" +
"<table>" +
"<tr><th>&nbsp;</th></tr>" +
"<tr><td>" + baseline["Games"] + "</td></tr>" +
"<tr><td>" + baseline["Plies"].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline["Analyzed"] + "</td></tr>" +
"<tr><td>" + baseline["ECO_rate"].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline["T1_rate"].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline["T2_rate"].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline["T3_rate"].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline["ET3_rate"].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline["Best_rate"].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline["Sound_rate"].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline["Forced_rate"].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline["Deltas"] + "</td></tr>" +
"<tr><td>" + baseline['avg_diff'].toFixed(1) + "</td></tr>" +
"<tr><td>" + baseline['median'].toFixed(1) + "</td></tr>" +
"<tr><td>" + baseline['std_dev'].toFixed(1) + "</td></tr>" +
"<tr><td>" + baseline['cheat_score'].toFixed(0) + "</td></tr>" +
"<tr><td>" + baseline['perp_len'].toFixed(1) + "</td></tr>" +
"</table>" +
"</td>";

}

countersTable += "</tr>";

}

//  for (index = 1; index < Positions.length; index++) {

document.getElementById('counters').innerHTML = countersTable + "</table>";

}; // end init()

$('#flipBoard').on('click', function() {
//  console.log( board.orientation());
  board.flip();
  onMoveEnd();
});

$('#setStartBtn').on('click', function() {
  positionIndex=0;
  variationIndex=0;
//  board.position( neo4j_root_FEN);
//  updateMovelist2();

  currentGame.reset();
  board.position( currentGame.fen());
});

$('#setNextMove').on('click', function() {
//console.log( "Pos: " + positionIndex + " Var: " + variationIndex);
/*
  if( variationIndex > 0)
    board.position( Positions[positionIndex+1][_T1_FEN][++variationIndex]);
  else
    board.position( Positions[++positionIndex][_FEN]);
  updateMovelist2();
*/
  currentGame.move( Positions[++positionIndex][_MOVE]);
  board.position( currentGame.fen());
});

$('#setPrevMove').on('click', function() {
//console.log( "Pos: " + positionIndex + " Var: " + variationIndex);
/*
  if( variationIndex > 0)
    board.position( Positions[positionIndex+1][_T1_FEN][--variationIndex]);
  else
    board.position( Positions[--positionIndex][_FEN]);
  updateMovelist2();
*/
  --positionIndex;
  currentGame.undo();
  board.position( currentGame.fen());
});

function setMove( pIndex, vIndex) {

  positionIndex = pIndex;
  variationIndex = vIndex;

  // Start from the beginning
  currentGame.reset();

  // Replay the game up to current move
  for (index = 1; index <= positionIndex; index++)
    currentGame.move( Positions[index][_MOVE]);

  board.position( currentGame.fen());

console.log( positionIndex + " " + variationIndex);
  
//console.log( "Pos: " + index + " Var: " + vindex + " FEN: " + Positions[positionIndex+1][_T1_FEN][variationIndex]);
/*
  if( variationIndex > 0)
    board.position( Positions[positionIndex+1][_T1_FEN][variationIndex]);
  else
    board.position( Positions[++positionIndex][_FEN]);
  updateMovelist2();
*/

}

// Update Position details area
var updatePosition = function() {

  // FEN to display
  var FEN       = Positions[positionIndex][_FEN];
  var zkey      = Positions[positionIndex][_ZKEY];
  var p_eval    = Positions[positionIndex][_SCORE];
  var p_depth   = Positions[positionIndex][_DEPTH];
  var p_time    = Positions[positionIndex][_TIME];

  if( variationIndex > 0) { 
        FEN     = Positions[positionIndex+1][_T1_FEN][variationIndex];
        zkey    = Positions[positionIndex+1][_T1_ZKEY][variationIndex];
        p_eval  = Positions[positionIndex+1][_T1_SCORE][variationIndex-1];
        p_depth = Positions[positionIndex+1][_T1_DEPTH][variationIndex-1];
        p_time  = Positions[positionIndex+1][_T1_TIME][variationIndex-1];
  }

  // Mate in X moves handling
  var mateLine = 0;
  if( p_eval.charAt(0)=="M") {
    mateLine = 1;
    var y = p_eval.replace(/^M/, '');
    p_eval = y;
  }

  // Evaluation color (+White/-Black)
  var evalColor = "<span>";

  posInd = positionIndex;
  if( variationIndex > 0) posInd = positionIndex + variationIndex;

//  console.log( positionIndex + " " + variationIndex + " " + p_eval + " " + p_eval.indexOf("-"));

  // White with negative eval OR Black with Positive eval
  if( (posInd%2==0 && p_eval.indexOf("-") == -1) ||
        (posInd%2 && p_eval.indexOf("-") > -1)) {
    evalColor = "<span class='Black'>";
    // Invert the evaluation
    if( p_eval.length && p_eval.indexOf("-") == -1) p_eval = 0 - p_eval;
  } else { 
    // Strip the minus
    if( p_eval.length) p_eval = Math.abs( p_eval);
  }

  // FEN string
  var positionStr = "<p><span class='FEN'>FEN: " + FEN + "<br/>Key: " + zkey + "</span></p>" + "Move evaluation: " + evalColor;

  // Eval string
  if( mateLine) positionStr += "Mate in " + Math.abs( p_eval);
  else if( p_eval !== "") positionStr += (p_eval/100).toFixed(2); else;

  // Depth string
  positionStr += "</span>" + " (depth: " + p_depth + 
        "ply, time: " + p_time + "ms)";
  document.getElementById('position').innerHTML = positionStr;
}

// Update movelist (new model) on button click
var updateMovelist2 = function() {
  moveList="";
  if( Game['analyze'] != "") moveList = "<h3>Side analysis condition: " + Game['analyze'] + "</h3>";
  moveList += "<table cellspacing=0 cellpadding=0 border=0 class='mlTable'>";
  moveList += "<tr><th>#</th><th colspan=4>White moves</th><th>Eval</th><th colspan=4>Black moves</th></tr>";

  for (index = 0; index < Positions.length-1; index++) {

        // Add move number
        if( index%2 == 0) {
                moveList += "<tr class='mlRow" + index%4 + "'><td>" + (~~(index/2)+1) + ".</td>";
        }

//  console.log( Positions[index+1][_MOVE]);

        // Current move
        if( index === positionIndex-1 && variationIndex == 0) {
                moveList += "<td><b><span class='mark_" + Positions[index+1][_MARK] + "'>" + Positions[index+1][_MOVE] + "</span></b></td><td><b>" + 
                Positions[index+1][_EVAL] + "</b></td><td><b><abbr title=\"" + Positions[index+1][_OPENING] + " " + Positions[index+1][_VARIATION] + "\">" 
                + Positions[index+1][_ECO] + "</abbr></b></td>";
        // Any other regular move
        } else {
                moveList += "<td><a href=" + window.location.pathname + "#" + (index+1) + " onclick='return setMove( " + (index+1) + ", 0);'>"
                + "<span class='mark_" + Positions[index+1][_MARK] + "'>" + Positions[index+1][_MOVE] + "</span></a></td><td>" + 
                Positions[index+1][_EVAL] + "</td><td><abbr title=\"" + Positions[index+1][_OPENING] + " " + Positions[index+1][_VARIATION] + "\">"
                + Positions[index+1][_ECO] + "</abbr></td>";
        }

        if( index%2 != 0) { 
		moveList += "</tr>"; 
	}
  }

  document.getElementById('movelist').innerHTML = moveList;

  // Show position details
  updatePosition();
}

// Update movelist on button click
var updateMovelist = function() {
  moveList="";
  if( Game['analyze'] != "") moveList = "<h3>Side analysis condition: " + Game['analyze'] + "</h3>";
  moveList += "<table cellspacing=0 cellpadding=0 border=0 class='mlTable'>";
  moveList += "<tr><th>#</th><th colspan=4>White moves</th><th>Eval</th><th colspan=4>Black moves</th></tr>";
  var index;
  // Get the maximum element of the evaluations array in order to normalize the scale
  var max=0;
  for (index = 1; index < Positions.length; index++) {
        var mabs=Math.abs(Positions[index][_SCORE]);
        if( mabs<1000 && mabs>max) { max=mabs;}
        if( Positions[index][_SCORE].indexOf("M") ===0 || mabs>1000) { max=1000; break;}
  }
  for (index = 0; index < Positions.length-1; index++) {
        // Choose which div (B/W) to hide
        currEval=Positions[index+1][_SCORE];
        scaleWidth=Math.round(Math.abs(currEval)/max*30);
        if( Math.abs(currEval)>1000) { scaleWidth=30; } // Limit width for the eval scale
        if((index%2 == 1 && currEval<0) || (index%2 == 0 && currEval>0)) { 
                hideWhite="";
                hideBlack="visibility:hidden;";
        } else {
                hideWhite="visibility:hidden;";
                hideBlack="";
        }
        if( currEval.indexOf("M") ===0 ) {              // Special mate-in-x-moves condition
        scaleWidth=30; 
        if((index%2 == 1 && currEval.indexOf("-")==1) || (index%2 == 0 && currEval.indexOf("-")==-1)) { 
                hideWhite="";
                hideBlack="visibility:hidden;";
        } else {
                hideWhite="visibility:hidden;";
                hideBlack="";
        }
/*
        if( currEval.indexOf("M0") ===0){
        if( index%2==1) {               // Special MATE condition
                hideWhite="visibility:hidden;";
                hideBlack="";
        } else {
                hideWhite="";
                hideBlack="visibility:hidden;";
        }
        }
*/
        }
        scaleDivs = "<td style='padding:0;background-color:#aaa;'><div class='MoveW' style='width:" + scaleWidth + "px;" + hideWhite + "'></div></td>" +
                "<td style='padding:0;background-color:#aaa;'><div class='MoveB' style='width:" + scaleWidth + "px;" + hideBlack + "'></div></td>";
        // Add move number
        if( index%2 == 0) {
                moveList += "<tr class='mlRow" + index%4 + "'><td>" + (~~(index/2)+1) + ".</td>";
        }
        // Right (black) part of scale div
        if( index%2 == 1) { moveList += scaleDivs + "</tr></table></td>"; }
        // Current move
        if( index === positionIndex-1 && variationIndex == 0) {
                moveList += "<td><b><span class='mark_" + Positions[index+1][_MARK] + "'>" + Positions[index+1][_MOVE] + "</span></b></td><td><b>" + 
                Positions[index+1][_EVAL] + "</b></td><td><b><abbr title=\"" + Positions[index+1][_OPENING] + " " + Positions[index+1][_VARIATION] + "\">" 
                + Positions[index+1][_ECO] + "</abbr></b></td>";
        // Any other regular move
        } else {
                moveList += "<td><a href=" + window.location.pathname + "#" + index + " onclick='return setMove( " + index + ", 0);'>"
                + "<span class='mark_" + Positions[index+1][_MARK] + "'>" + Positions[index+1][_MOVE] + "</span></a></td><td>" + 
                Positions[index+1][_EVAL] + "</td><td><abbr title=\"" + Positions[index+1][_OPENING] + " " + Positions[index+1][_VARIATION] + "\">"
                + Positions[index+1][_ECO] + "</abbr></td>";
        }
        moveList += "<td><table cellspacing=0 cellpadding=0 border=0 style='font-size: 75%;'><tr>";
        // Add T1, if present
        if( Positions[index+1][_T1_MOVE] && Positions[index+1][_T1_MOVE].length) {
        for (j = 0; j < Positions[index+1][_T1_MOVE].length; j++) {
                if( index === positionIndex && j == variationIndex-1)
                moveList += "<td><b>" + Positions[index+1][_T1_MOVE][j] + "</b></td>";
                else
                moveList += "<td><a href=" + window.location.pathname + "#" + index + 
                        " onclick='return setMove( " + index + ", " + (j+1) + ");'>" + Positions[index+1][_T1_MOVE][j] + "</a></td>";
                if(j%2==1) moveList += "</tr><tr>";
        }
        if( Positions[index+1][_T1_MOVE].length%2==0) moveList += "<td></td>";
        } else 
                moveList += "<td></td>";
        moveList += "</tr></table></td>";
        // Left (white) part of scale div
        if( index%2 == 0) { moveList += "<td style='background-color:#aaa;'><table border=0 cellspacing=0 cellpadding=0 align=center><tr>" + scaleDivs + "</tr><tr>"; }
  }

  document.getElementById('movelist').innerHTML = moveList;

  // Show position details
  updatePosition();
}

function colorScore( score) {

        if( score>=300) {
    scoreStr = "<span class='Crit'>" + score + "</span>";
  } else if ( score>=200) {
    scoreStr = "<span class='Warn'>" + score + "</span>";
  } else {
    scoreStr = "<span class='Info'>" + score + "</span>";
  }

  return scoreStr;
}

$(document).ready(setCookie("page",0,1));

