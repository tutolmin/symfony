function pageLoad( gid) {

  // Show the game in analysis tab
  showGameDetails( gid);

  // Show Pending games in a queue
  $('.tagsinput#queue-form-tags').importTags( 'Pending;');
  loadQueue();

  // Show white wins
  $('.tagsinput#form-tags').importTags( '1-0;');
  loadGames();

}

function parse_url( url) {
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
var W_analysis_depth, B_analysis_depth;
var hasEvaluation;

var TimeOut;
var timerIsOn = false;




// Array of accordions
var accordions = ['u-accordion','q-accordion','s-accordion', 'a-accordion', 'l-accordion'];
var a;
for (a = 0; a < accordions.length; a++) {

  var acc = document.getElementsByClassName( accordions[a]);
  var i;
  // Bind listeners for accordion items click
  for (i = 0; i < acc.length; i++) {
    acc[i].addEventListener("click",
      show_accordion_item.bind(this, acc[i], accordions[a]), false);
  }
}

// Open accordion item and close others
function show_accordion_item( btn, ac) {

  var acc = document.getElementsByClassName( ac);
  var j;
  for (j = 0; j < acc.length; j++) {

    acc[j].classList.remove("active-accordion");
    var pan = acc[j].nextElementSibling;
    pan.style.maxHeight = null;
  }

  btn.classList.add("active-accordion");
  var panel = btn.nextElementSibling;
  panel.style.maxHeight = panel.scrollHeight + "px";
}



/*
// Does NOT work!!!
$("#checkAll").click(function(){
    console.log("Check all games");
    $("input[name='items[]']").not(this).prop('checked', this.checked);
});
*/

// Send file to a user
function downloadPGN(filename, text) {
  var element = document.createElement('a');
  element.setAttribute('href', 'data:application/x-chess-pgn;charset=utf-8,' + encodeURIComponent(text));
  element.setAttribute('download', filename);

  element.style.display = 'none';
  document.body.appendChild(element);

  element.click();

  document.body.removeChild(element);
}


// A selection of games have been exported as PGN
function exportGameList() {

  var gids = [];
  $("input[name='items[]']:checked").each(function () {
    gids.push( $(this).val());
  });

  console.log( "Game IDs: " + JSON.stringify( gids));

  $.post( "exportPGNs", { gids: JSON.stringify( gids)},
    function(result) { downloadPGN( 'chesscheat.pgn', result); });
}


// A game was exported from Analyze tab
function exportGame( gid) {

//  var gids = [document.getElementById("game_being_analyzed").value];
  var gids = [gid];

  $.post( "exportPGNs", { gids: JSON.stringify( gids)},
    function(result) {
      result = result.replace(/(?:\r\n|\r|\n)/g, '<br>');
//      console.log( result);
      document.getElementById("gamePGN").innerHTML = result;
//downloadPGN( 'chesscheat.pgn', result);
  });
}


// A selection of games have been submitted for analysis
function processGameList() {

  var s = document.getElementById("sideToAnalyzeGroup").value;
  var d = document.getElementById("AnalysisDepthGroup").value;

  var gids = [];
  $("input[name='items[]']:checked").each(function () {
    gids.push( $(this).val());
  });

  console.log( "Game IDs: " + JSON.stringify( gids) + " side: " + s + " depth: " + d);

  $.post( "queueGameAnalysis", { gids: JSON.stringify( gids), side: s, depth: d},
    function(result) { document.getElementById('processGameStatusGroup').innerHTML = result });
}


// A game was submitted for analysis from Analyze tabe
function processGame() {

  var s = document.getElementById("sideToAnalyze").value;
  var d = document.getElementById("AnalysisDepth").value;

  var gids = [document.getElementById("game_being_analyzed").value];

  console.log( "Game ID: " + gids[0] + " side: " + s + " depth: " + d);

  $.post( "queueGameAnalysis", { gids: JSON.stringify( gids), side: s, depth: d},
    function(result) { document.getElementById('processGameStatus').innerHTML = result });
}

// Display analysis queue with certain tag
function showQueueTag( tag) {

  console.log( "Game ID: " + tag);

  $('.tagsinput#queue-form-tags').importTags( tag + ';');

  loadQueue();

  // Issue a click event on a Queue tab
  document.getElementById("queueTab").click();
}

// Display game data on the Analyze tab
function showGameDetails( gid) {

  // Section has been specified
  section = '';
  if( gid == 'upload' || gid == 'search' || gid == 'queue') {
    section = gid; gid = 0;
  }

  console.log( "Game ID: " + gid + " section: " + section);

  // Clear the tab elements prior to new game load
  document.getElementById('gamePlayers').innerHTML = "Loading...";
  document.getElementById('gameEDR').innerHTML = "Loading...";
  document.getElementById('gameFEN').innerHTML = "Loading...";
  document.getElementById('gameECO').innerHTML = "Loading...";
  document.getElementById('gamePGN').innerHTML = "Loading...";
  document.getElementById('moveList').innerHTML = "Loading...";
  document.getElementById('counters').innerHTML = "Loading...";

  // reset to starting position if this is NOT the first loaded game
  if( typeof Positions !== 'undefined')
    $('#setStartBtn').click();

  // Fetch game data from the DB
  $.getJSON( URI_arr.join("/") + '/getGameDetails', 'gid='+gid, function(data){

  Game = new Object;
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
  W_analysis_depth = Game["W_analysis_depth"];
  B_analysis_depth = Game["B_analysis_depth"];
  hasEvaluation = Game["hasEvaluation"];

  // Replay game moves and display new game data
  if( section.length > 0) { init( false); } else { init(); }

  // Special hidden field in case a user submits the game for analysis
  document.getElementById( 'game_being_analyzed').value = Game["Hash"];

  // Show PGN in a div
  exportGame( Game["Hash"]);

  // Display a move list for a new game
  updateMovelist();

  });

  // Specific section or game ID
  if( section == 'upload') {

    // Issue a click event on an Upload tab
    document.getElementById("uploadTab").click();

  } else if ( section == 'search') {

      // Issue a click event on an Search tab
    document.getElementById("searchTab").click();

  } else if ( section == 'queue') {

      // Issue a click event on an Queue tab
    document.getElementById("queueTab").click();

  } else {

    // Issue a click event on an Analyze tab
    document.getElementById("analysisTab").click();
  }
}


// Set a cookie
function setCookie(cname, cvalue, exdays) {
    var d = new Date();
    d.setTime(d.getTime() + (exdays*24*60*60*1000));
    var expires = "expires="+ d.toUTCString();

    // Special handling of pagination cookies
    if( cname == "gl_page" || cname == "qa_page") {
	if( cvalue == "prev") {
	   var curr = getCookie(cname);
	   if( +curr > 0) {
	      cvalue = +curr - 1;
	   }
	}
	if( cvalue == "next") {
	   var curr = getCookie(cname);
	   cvalue = +curr + 1;
	}
    }

    document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
}


// Fetch a cookie from user browser
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


// Parse a tags string from a scpfieid form element
function parseTags( element) {

  // Parse the tags
  var tags_arr = document.getElementById( element).value.split(';');
  var tags_str = "";

  console.log( tags_arr);

  // Iterate all tags
  tags_arr.forEach( function( item, i, arr) {

  // We want a tag to be at leaset 2 chars long
  if( item.length>2) {

    var re;

    // Game result has been specified
    if( item == "1-0" || item == "0-1" || item == "1/2-1/2") {
      tags_str += "result:" + item + ";";
      console.log( item);
      return;
    }

    // Has a color specification
    re = /^([\w,\.\ ]+)((\ |_)as(\ |_)(white|black))$/i;
    if( found_color = item.match( re)) {
      tags_str += found_color[5].toLowerCase() + ":" + found_color[1] + ";";
      console.log( found_color);
      return;
    }

    // Has a result specification
    re = /^([\w,\.\ ]+)((\ |_)(wins|loses|draws))$/i;
    if( found_result = item.match( re)) {
      tags_str += found_result[4].toLowerCase() + ":" + found_result[1] + ";";
      console.log( found_result);
      return;
    }

    // Hash (game hash in the DB)
    re = /^([0-9a-z]{64})$/i;
    if( found_hash = item.match( re)) {
      tags_str += "hash:" + found_hash[1] + ";";
      console.log( found_hash);
      return;
    }

    // Game ending type
    re = /^(stale|check)mate((\ |_)by(\ |_)(pawn|king|queen|rook|knight|bishop))?$/i;
    if( found_final = item.match( re)) {
      tags_str += "ending:" + found_final[1] + "mate;";
      if( typeof found_final[5] !== 'undefined')
        tags_str += "piece:" + found_final[5] + ";";
      console.log( found_final);
      return;
    }

    // Analysis side
    re = /^(white|black)$/i;
    if( found_status = item.match( re)) {
      tags_str += "side:" + found_status[0] + ";";
      console.log( found_status);
      return;
    }

    // Analysis type
    re = /^(fast|deep)$/i;
    if( found_type = item.match( re)) {
      tags_str += "type:" + found_type[0] + ";";
      console.log( found_type);
      return;
    }

    // Game status label
    re = /^(complete|processing|pending|skipped|partially|evaluated|exported)$/i;
    if( found_status = item.match( re)) {
      tags_str += "status:" + found_status[0] + ";";
      console.log( found_status);
      return;
    }

    // Has ECO specification
    re = /^[A-E]{1}[0-9]{2}$/i;
    if( found_eco = item.match(re)) {
      tags_str += "eco:" + found_eco[0] + ";";
      console.log( found_eco);
      return;
    }

    // email address https://www.w3resource.com/javascript/form/email-validation.php
    re = /^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/;
    if( found_email = item.match(re)) {
      tags_str += "email:" + found_email[0] + ";";
      console.log( found_email);
      return;
    }

    // Special switches
    re = /^(effectiveResult|resultMismatch)$/;
    if( found_switch = item.match( re)) {
      tags_str += "switch:" + found_switch[0] + ";";
      console.log( found_switch);
      return;
    }

    // Just a player
    tags_str += "player:" + item + ";";
  }
  });

  return tags_str;
}


            $('#queue-form-tags').tagsInput({
                    'unique': true,
                    'minChars': 2,
                    'maxChars': 64,
                    'limit': 5,
                    'delimiter': [';'],
                    'validationPattern': new RegExp('^[a-zA-Z0-9,\.\ \@\/_+-]+$'),
                    'onAddTag': function(input, value) {
                        setCookie('qa_page',0,1);
                        loadQueue();
                    },
                    'onRemoveTag': function(input, value) {
                        setCookie('qa_page',0,1);
                        loadQueue();
                    },
                    'onChange': function(input, value) {
                    },

    'autocomplete': {
      source: "searchHint",
      minLength: 2,
      select: function( event, ui ) {
        console.log( "Selected: " + ui.item.value);
      }
    }
            });


            $('#form-tags').tagsInput({
                    'unique': true,
                    'minChars': 2,
                    'maxChars': 64,
                    'limit': 5,
                    'delimiter': [';'],
                    'validationPattern': new RegExp('^[a-zA-Z0-9,\.\ \@\/_+-]+$'),
                    'onAddTag': function(input, value) {
                        setCookie('gl_page',0,1);
                        loadGames();
                    },
                    'onRemoveTag': function(input, value) {
                        setCookie('gl_page',0,1);
                        loadGames();
                    },
                    'onChange': function(input, value) {
                    },

    'autocomplete': {
      source: "searchHint",
      minLength: 2,
      select: function( event, ui ) {
        console.log( "Selected: " + ui.item.value);
      }
    }
            });

//  'autocomplete_url':'http://dev.chesscheat.com/autocomplete.php',
//  'autocomplete':{selectFirst:true,width:'100px',autoFill:true},





function openTab(evt, tabName) {

    if( tabName != "Analyze")
      pausePlayback();

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



// Get the element with id="analysisTab" and click on it
document.getElementById("analysisTab").click();


var onMoveEnd = function() {

  // Get board element
  var boardEl = $('#board');
  var squareClass = 'square-55d63';

  // Remove all existing highligths
  boardEl.find('.' + squareClass).removeClass('highlight-white');
  boardEl.find('.' + squareClass).removeClass('highlight-black');
  boardEl.find('.' + squareClass).removeClass('highlight-capture');

  // Get current game history
  var history = currentGame.history({ verbose: true });

//  console.log( history);

  // Highlight at leas last move
  if( history.length > 0) {

    // Squares from/to
    var from = history[history.length-1].from;
    var to = history[history.length-1].to;

    // Last move was Black
    if( history.length % 2 == 0) {

      boardEl.find('.square-' + from).addClass('highlight-black');
      boardEl.find('.square-' + to).addClass('highlight-black');

    } else {

      boardEl.find('.square-' + from).addClass('highlight-white');
      boardEl.find('.square-' + to).addClass('highlight-white');
    }

    // We have 2+ moves in history
    if( history.length > 1) {

      // Squares from/to
      from = history[history.length-2].from;
      var prev_to = history[history.length-2].to;

      // Last move was Black
      if( (history.length + 1) % 2 == 0) {

        boardEl.find('.square-' + from).addClass('highlight-black');
        boardEl.find('.square-' + prev_to).addClass('highlight-black');

      } else {

        boardEl.find('.square-' + from).addClass('highlight-white');
        boardEl.find('.square-' + prev_to).addClass('highlight-white');
      }

      // Special case for capture on the same square
      if( to == prev_to)
        boardEl.find('.square-' + to).addClass('highlight-capture');
    }
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
//const _FEN      = 1;
//const _ZKEY     = 2;
const _ECO      = 1;
const _OPENING  = 2;
const _VARIATION= 3;
//const _EVAL     = 6;
const _MARK     = 4;		// Forced / Sound / Best
const _SCORE    = 5;
const _DEPTH    = 6;
const _TIME     = 7;
const _VARS     = 8;
const _VAR1     = 0;
const _VAR2     = 1;
const _VAR3     = 2;
const _VAR_MOVE = 0;
const _VAR_SCORE= 1;
const _VAR_DEPTH= 2;
const _VAR_TIME = 3;
const _T1_MOVE  = 8;
//const _T1_FEN   = 12;
//const _T1_ZKEY  = 13;
const _T1_SCORE = 9;
const _T1_DEPTH = 10;
const _T1_TIME  = 11;

// New game has been loaded for analysis
var init = function( play = true) {

positionIndex=0;
alternativeIndex=-1;
variationIndex=-1;
currentGame.reset();
board.position( currentGame.fen());

console.time("Process positions");

// Replay the game and convert LAN to SAN
for (index = 1; index < Positions.length; index++) {

//  console.timeLog("Process positions");

  // We only process actual game moves here
  // in order to be faster
  // alternatives will be processed when particular
  // game move is selected
  var cmove = currentGame.move( Positions[index][_MOVE], {sloppy: true});
  Positions[index][_MOVE] = cmove.san;
}

console.timeEnd("Process positions");

currentGame.reset();

// Start playing the moves if autostart is true
console.log( "Autoplay: " + play)
if( play) {

  timerIsOn = true;
  window.setTimeout( makeNextMove, 1000);
}

// Show game header
var WhiteELO = "";
if( Game['W_ELO'] != "" && Game['W_ELO'] != 0) { WhiteELO = " (" + Game['W_ELO'] + ")";}
var BlackELO = "";
if( Game['B_ELO'] != "" && Game['B_ELO'] != 0) { BlackELO = " (" + Game['B_ELO'] + ")";}
var gamePlayers = Game['White'] + WhiteELO + " vs. " + Game['Black'] + BlackELO;
document.getElementById('gamePlayers').innerHTML = gamePlayers;

var ECO_opening_variation ="";
if( Game['ECO'] != "") { ECO_opening_variation = Game['ECO'];}
if( Game['ECO_opening'] != "") { ECO_opening_variation += ": " + Game['ECO_opening'];}
if( Game['ECO_variation'] != "") { ECO_opening_variation += ", " + Game['ECO_variation'];}
document.getElementById('gameECO').innerHTML = ECO_opening_variation;

var gameEDR = Game['Event'] + ", " + Game['Date'] + ", " + Game['Result'];
//if( Game['eResult'] != "" && Game['eResult'] != Game['Result']) {
//  gameDetails += " (effectively " + Game['eResult'] + ")"; }
document.getElementById('gameEDR').innerHTML = gameEDR;

// Show initial counters
if( B_analysis_depth > 0 && W_analysis_depth == 0) {
  $('#blackSide').click();
} else {
  $('#whiteSide').click();
}

}; // end init()


// Update counters for a side
function updateCounters( side) {

// Show game details
var countersTable = "<table border=0 cellspacing=0 cellpadding=0 class='gameInfo'>";

// Select which side analysis data to show, White has a priority
var prefix = "W_";

// Unless specific side requested
if( side == "White") prefix = "W_";
if( side == "Black") prefix = "B_";

countersTable += "<tr><td>" +
"<table>" +
"<tr><th>" + ((prefix=="W_")?Game['White']:Game['Black']) + "</th></tr>" +
"<tr><td>Games</td></tr>" +
"<tr><td><abbr title='Total number of moves per game'>Plies</abbr></td></tr>" +
"<tr><td><abbr title='Number of analyzed moves'>Analyzed</abbr></td></tr>" +
"<tr><td><abbr title='Encyclopedia of Chess Openings'>ECO</abbr>, %</td></tr>" +
"<tr><td><abbr title='Percentage of first line moves'>T1</abbr>, %</td></tr>" +
"<tr><td><abbr title='Percentage of first and second line moves'>T2</abbr>, %</td></tr>" +
"<tr><td><abbr title='Percentage of top three line moves'>T3</abbr>, %</td></tr>" +
"<tr><td><abbr title='Percentage of top three line moves, including ECOs'>ET3</abbr>, %</td></tr>" +
"<tr><td><abbr title='Percentage of best moves'>Best</abbr>, %</td></tr>" +
"<tr><td><abbr title='Percentage of sound moves'>Sound</abbr>, %</td></tr>" +
"<tr><td><abbr title='Percentage of forced moves'>Forced</abbr>, %</td></tr>" +
"<tr><td><abbr title='Total number of moves with evaluation differences with the first line'>Deltas</abbr></td></tr>" +
"<tr><td><abbr title='Average Difference of the error'>A.&nbsp;D.</abbr>,&nbsp;<abbr title='Centipawns (100 cp = 1 pawn)'>cp</abbr></td></tr>" +
"<tr><td><abbr title='Median error'>Median</abbr>,&nbsp;<abbr title='Centipawns (100 cp = 1 pawn)'>cp</abbr></td></tr>" +
"<tr><td><abbr title='Standard Deviation'>S.&nbsp;D.</abbr>,&nbsp;<abbr title='Centipawns (100 cp = 1 pawn)'>cp</abbr></td></tr>" +
"<tr><td><abbr title='Distance to a baseline'>bDist</abbr></td></tr>" +
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
"<tr><td>" + Game[prefix+'perp_len'].toFixed(1) + "</td></tr>" +
"</table>" +
"</td>";

if( false)
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

if( Game[prefix+'cheat_score'] < 500)
  document.getElementById("cheatScore").innerHTML =
    "Cheat Score: <abbr title='To be determined'>TBD</abbr>";
else
  document.getElementById("cheatScore").innerHTML =
    "Cheat Score: " + Game[prefix+'cheat_score'];


// Hide intro text
if( hasEvaluation == "false" ) {
//&& W_analysis_depth == 0 && B_analysis_depth == 0) {

  document.getElementById("evaluationContainer").style.visibility = "hidden";

  document.getElementById("mainContainer").style.zIndex = "1";
  document.getElementById("mainContainer").style.visibility = "visible";

  document.getElementById("countersContainer").style.visibility = "hidden";

  document.getElementById("accordionContainer").style.zIndex = "1";
  document.getElementById("accordionContainer").style.visibility = "visible";

// Bring analysis data to the front and make it visible
} else {

  document.getElementById("mainContainer").style.visibility = "hidden";

  document.getElementById("evaluationContainer").style.zIndex = "1";
  document.getElementById("evaluationContainer").style.visibility = "visible";

// show counters for specific side
if( (side == "White" && W_analysis_depth > 0)
  || (side == "Black" && B_analysis_depth > 0)) {

  document.getElementById("accordionContainer").style.visibility = "hidden";

  document.getElementById("countersContainer").style.zIndex = "1";
  document.getElementById("countersContainer").style.visibility = "visible";

} else {

  document.getElementById("countersContainer").style.visibility = "hidden";

  document.getElementById("accordionContainer").style.zIndex = "1";
  document.getElementById("accordionContainer").style.visibility = "visible";
}

}

document.getElementById('counters').innerHTML = countersTable + "</table>";

}



// Makes next move until the end
// Then loads new game
function makeNextMove() {

  $('#setNextMove').click();

  if( timerIsOn)
    TimeOut = window.setTimeout( makeNextMove, 1000);
}

$('#boardFlip').on('click', function() {
  board.flip();
  onMoveEnd();
});

$('#tagsDelete').on('click', function() {
  $('.tagsinput#form-tags').importTags( '');
  loadGames();
});

$('#tagsReload').on('click', function() {
  setCookie('gl_page',0,1);
  loadGames();
});

$('#tagsDeleteQueue').on('click', function() {
  $('.tagsinput#queue-form-tags').importTags( '');
  loadQueue();
});

$('#tagsReloadQueue').on('click', function() {
  setCookie('qa_page',0,1);
  loadQueue();
});

$('#setStartBtn').on('click', function() {
  positionIndex=0;
  alternativeIndex=-1;
  variationIndex=-1;

  timerIsOn = false;
  clearTimeout( TimeOut);

  currentGame.reset();
  board.position( currentGame.fen());

  // Clear alternative lines
  for (altidx = 0; altidx < 3; altidx++) {

    document.getElementById('varEval'+(altidx+1)).innerHTML = '';
    document.getElementById('var'+(altidx+1)).innerHTML = '';
  }

  updateMovelist();
});

$('#setPlayPause').on('click', function() {

  if( timerIsOn)
    pausePlayback();
  else {
    timerIsOn = true;
    makeNextMove();
  }
});

function pausePlayback() {

  timerIsOn = false;
  clearTimeout( TimeOut);
};

$('#setNextMove').on('click', function() {

//  console.log( "Next btn clk - Pos: " + positionIndex + " Alt: " + alternativeIndex +
//	  " Var: " + variationIndex + " Timer: " + timerIsOn);

  // Make next variation move
  if( variationIndex >= 0) {

      if (typeof Positions[positionIndex][_VARS][_VAR_MOVE][alternativeIndex][variationIndex+1] !== 'undefined') {
        variationIndex++;
        currentGame.move( Positions[positionIndex][_VARS][_VAR_MOVE][alternativeIndex][variationIndex]);
	console.log( "Variation move: " + Positions[positionIndex][_VARS][_VAR_MOVE][alternativeIndex][variationIndex]);
      }

  // Make actual game move
  } else {

      if (typeof Positions[positionIndex+1] !== 'undefined') {
	  positionIndex++;
	  currentGame.move( Positions[positionIndex][_MOVE]);
	  console.log( "Actual move: " + Positions[positionIndex][_MOVE]);

      // No next move, load new game
      } else

      // Timer is still on, load new game
      if( timerIsOn) {
        timerIsOn = false;
        clearTimeout( TimeOut);
	window.setTimeout( showGameDetails, 5000, 0);
      }
  }

  board.position( currentGame.fen());

  updateMovelist();
});

$('#setPrevMove').on('click', function() {

  console.log( "Prev btn clk - Pos: " + positionIndex + " Alt: " + alternativeIndex +
	  " Var: " + variationIndex);

  // Remove timer
  timerIsOn = false;
  clearTimeout( TimeOut);

  // Update variation index
  if( variationIndex > 0)

    variationIndex--;

  // Update game move index
  else if( positionIndex > 0) {

    positionIndex--;

    // Exiting variation line
    alternativeIndex=-1;
    variationIndex=-1;
  }

  currentGame.undo();
  board.position( currentGame.fen());

  updateMovelist();
});

function setMove( pIndex, aIndex, vIndex) {

  positionIndex = pIndex;
  alternativeIndex = aIndex-1;
  variationIndex = vIndex-1;

  // Remove timer
  timerIsOn = false;
  clearTimeout( TimeOut);

  console.log( "Set move - Pos: " + positionIndex +
	  " Alt: " + alternativeIndex +
	  " Var: " + variationIndex);

  // Start from the beginning
  currentGame.reset();

  // Replay the game up to current move
  for (index = 0; index < positionIndex; index++)
    currentGame.move( Positions[index+1][_MOVE]);

  // Replay variation
  if( variationIndex >= 0) {

    // Undo last game move
    currentGame.undo();

    // Replay variation moves
    for (index = 0; index <= variationIndex; index++)
      currentGame.move( Positions[pIndex][_VARS][_VAR_MOVE][alternativeIndex][index]);
  }

  board.position( currentGame.fen());

  updateMovelist();
}

// Get evaluation string for a move
var getEvalString = function( game, posIndex, altIndex, varIndex) {

  // Game move eval
  var p_eval    = Positions[posIndex][_SCORE];

  // Variation move eval
  if( altIndex >= 0 && varIndex >= 0) {
    p_eval  = Positions[posIndex][_VARS][_VAR_SCORE][altIndex][varIndex];
  }

//  console.log( "Evaluation string for p/a/v " +
//	  posIndex + "/" + altIndex + "/" + varIndex + ": '" + p_eval + "'");

  // Mate in X moves handling
  var mateLine = 0;
  if( p_eval.charAt(0)=="M") {
    mateLine = 1;
    var y = p_eval.replace(/^M/, '');
    p_eval = y;
  }

  // CentiPawn 500+ handling
  var pawnLine = 0;
  if( p_eval.charAt(0)=="P") {
    pawnLine = 1;
    var y = p_eval.replace(/^P/, '');
    p_eval = y;
  }

  // Evaluation color (+White/-Black)
  var evalColor = "<span>";

  // Cumulative index for main line and variation
  posInd = posIndex;
  if( varIndex > 0) posInd = posIndex + varIndex - 1;

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

  // Eval string starts with color span
  var evalStr = evalColor;

  // Eval string
  if( game.in_checkmate() && altIndex == -1) evalStr += "Checkmate!";
  else if( game.in_stalemate() && altIndex == -1)	evalStr += "Stalemate!";
  else if( mateLine)		evalStr += "Mate in " + Math.abs( p_eval);
  else if( pawnLine)		evalStr += p_eval + "+";
  else if( p_eval !== "")	evalStr += (p_eval/100).toFixed(2);

  return evalStr + '</span>';
}

// Update Position details area
var updatePosition = function() {

  // FEN to display
  var FEN       = currentGame.fen();
  var p_depth   = Positions[positionIndex][_DEPTH];
  var p_time    = Positions[positionIndex][_TIME];

  // Add move mark, if present
  var evalMark = '';
  if( Positions[positionIndex][_MARK])
    evalMark = '<span class="mark_' + Positions[positionIndex][_MARK] + '">&nbsp;' +
        Positions[positionIndex][_MARK] + '</span>';

  var evalStr = getEvalString( currentGame, positionIndex, -1, -1);

  // Only add eval string if data exists
  var positionStr = '';
  if( p_depth && p_time)
    positionStr = evalStr + evalMark +
    '&nbsp;<span style="font-size: 80%">(' + p_depth +
    "&nbsp;plies,&nbsp;" + p_time + "&nbsp;ms)</span>";

  document.getElementById('evaluationInfo').innerHTML = positionStr;
  document.getElementById('gameFEN').innerHTML = FEN;
}

// Update movelist
var updateMovelist = function() {

  moveList="";

  // Go through all the collected positions
  for (index = 0; index < Positions.length-1; index++) {

    // Move number
    if( index%2 == 0) {
      moveList += (~~(index/2)+1) + '. ';
    }

    // ECO code
    eco_str = '';
    if( Positions[index+1][_ECO] != '') {
      eco_str = '&nbsp;<abbr title="' + Positions[index+1][_OPENING] + " "
		+ Positions[index+1][_VARIATION] + '">'
                + Positions[index+1][_ECO] + '</abbr>';
    }

    // Current move in a real game, mark it bold
    if( index+1 === positionIndex && variationIndex == -1) {

      moveList += "<b><span class='mark_" + Positions[index+1][_MARK] + "'>" +
	Positions[index+1][_MOVE] + "</span></b>" + eco_str + " ";

    // Other regular game SAN, make a link
    } else {

      moveList += "<a href=" + window.location.pathname + "#" + (index+1) +
	" onclick='return setMove( " + (index+1) + ", 0, 0);'>" +
	"<span class='mark_" + Positions[index+1][_MARK] + "'>" +
	Positions[index+1][_MOVE] + "</span></a>" + eco_str + " ";
    }

    // Current move, show alternative lines
    if( index+1 === positionIndex) {

      // Undo last move while displaying actual game move
      if( variationIndex == -1)
        currentGame.undo();

      // Chess game for variation replay
      var moveVar = new Chess( currentGame.fen());

      // Replay game move to kkep the currentGame consistent
      if( variationIndex == -1)
        currentGame.move( Positions[positionIndex][_MOVE]);

      // Show alternative lines
      for (altidx = 0; altidx < 3; altidx++) {

        // Chess game for alternative replay
        var moveAlt = new Chess( moveVar.fen());

  	document.getElementById('varEval'+(altidx+1)).innerHTML = '';
        document.getElementById('var'+(altidx+1)).innerHTML = '';

	// There can be no alternatives (forced or not fetched)
	if( typeof Positions[index+1][_VARS][_VAR_MOVE][altidx] !== 'undefined'
	  && Positions[index+1][_VARS][_VAR_MOVE][altidx].length > 0) {

	  altList="";

	  var altEval = getEvalString( currentGame, positionIndex, altidx, 0);
  	  document.getElementById('varEval'+(altidx+1)).innerHTML = altEval;

	  // Go through the variation array
	  for (vindex = 0; vindex < Positions[index+1][_VARS][_VAR_MOVE][altidx].length; vindex++) {

	    // Move number for White moves
	    if( (index+vindex)%2 == 0) {

	      altList += (~~((index+vindex)/2)+1) + '. ';
	    }

	    // #. ... SAN for first black move only
	    else if( vindex == 0) {

	      altList += (~~((index+vindex)/2)+1) + '. ... ';
	    }

	    // Replay only when showing actual game move
	    // No need to replay for each variation move display
	    if( variationIndex == -1) {
/*
	      console.log( Positions[index+1][_VARS][_VAR_MOVE][altidx][vindex] +
		" index " + index + " altidx " + altidx + " vindex " + vindex);
*/
	      cmove = moveAlt.move( Positions[index+1][_VARS][_VAR_MOVE][altidx][vindex], {sloppy: true});
	      Positions[index+1][_VARS][_VAR_MOVE][altidx][vindex] = cmove.san;
	    }

	    // Mark current alternative move with bold
	    if( altidx == alternativeIndex && vindex == variationIndex) {

	      altList += "<b><span>" + Positions[index+1][_VARS][_VAR_MOVE][altidx][vindex] + "</span></b> ";

	    // Other regular alternative move
	    } else {

	      altList += "<a href=" + window.location.pathname + "#" + (index+1) +
		" onclick='return setMove( " + (index+1) + ", " + (altidx+1) + ", " + (vindex+1) + ");'>" +
		"<span>" + Positions[index+1][_VARS][_VAR_MOVE][altidx][vindex] + "</span></a> ";
	    }
	  }

	  // Put the collected altlist in place
	  document.getElementById('var'+(altidx+1)).innerHTML = altList;
        }
      }
    }
  }

  // Put the collected movelist in place
  document.getElementById('moveList').innerHTML = moveList;

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

$(document).ready(setCookie("gl_page",0,1));
$(document).ready(setCookie("qa_page",0,1));
