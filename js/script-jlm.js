/* theatre support */

function theatre_1(showtime, dur, filename) {
var motw = videojs('motw'); // define player
console.log(showtime);

/*$.ajax({
    type: "POST",
    url: '/motw/motw.php',
    dataType: 'json',
    data: {"data":"check"},
    success: function(data){
        alert(data.value1);
        alert(data.value2);
    }
 });*/

console.log(filename);

// set file
motw.ready(function(){
  this.src({type: 'video/mp4', src: '/motw/'+filename+'.mp4'});
});

motw.load();

//showtime = Math.floor(showtime / 1000); // define start time in seconds
var now = new Date().getTime(); now = now / 1000; // define now in seconds
var diff = Math.floor(now - showtime); // define current duration
console.log(dur);
console.log(new Date(showtime * 1000));

// reset on mouseup
$('#motw').mouseup(function(){
  now = new Date().getTime(); now = now / 1000;
  diff = Math.floor(now - showtime);
  motw.currentTime(diff);
});

// reload on showtime
function checkTime() {
  now = new Date().getTime(); now = now / 1000;
  if(now == showtime || now > showtime) {
    location.reload();
  }
  console.log('test');
}
if(now < showtime) {
  c = setInterval(checkTime,5000);
}

// change text on duration
function checkDur() {
  now = new Date().getTime(); now = now / 1000;
  if(now < showtime) {
    $('.np').html('<span style="color:orange;"></span>');
  }
  else if(now < showtime && now > (showtime - 900)) { // 15m or less before showtime
    $('.np').html('<span style="color:orange;">starting soon</span>');
  }
  else if(now > (showtime + ((dur/3)*2)) && now < (showtime + dur)) { // within 2/6 of showtime + dur (end)
    $('.np').html('<span style="color:orange;">almost over</span>');
  }
  else if(now > showtime && now < (showtime + dur)) { // if between showtime and end
    $('.np').html('<span style="color:green;">playing now</span>');
  }
  else if(now > (showtime + dur)) { // if past end
    $('.np').html('<span style="color:#b22222;">ended</span>');
  }
}

i = setInterval(checkDur(),60000);

// reflect consistent time
function resetTime() {
  now = new Date().getTime(); now = now / 1000;
  mct = Math.floor(motw.currentTime());
  diff = Math.floor(now - showtime);
  diffOver = diff + 1; diffUnder = diff - 1;

  if(mct > diffOver || mct < diffUnder) {
    diff = Math.floor(now - showtime);
    motw.currentTime(diff);
  }
}

t = setInterval(resetTime, 10000);
}

  /*for(i=0;i<welcomeCount;i++) {
    $('.welcome').delay(1000).fadeOut(200,function() {
        $(this).text(welcomeCount[i]).fadeIn(200).delay(1000);
        console.log(welcomeCount[i]);
    });
  }*/

function cycle() {
$('.welcome').delay(10000).fadeOut(500, function() {
  $(this).delay(500).text('Bienvenue au Cinema').fadeIn(500, function(){
    $(this).delay(10000).fadeOut(500, function() {
      $(this).delay(500).text('Добро пожаловать в кино').fadeIn(500, function() {
        $(this).delay(10000).fadeOut(500, function() {
          $(this).delay(500).text('劇場へようこそ').fadeIn(500, function() {
            $(this).delay(10000).fadeOut(500, function() {
              $(this).delay(500).text('쇼에 오신 걸 환영합니다').fadeIn(500, function() {
                $(this).delay(10000).fadeOut(500, function() {
                  $(this).delay(500).text('Bienvenido al Cine').fadeIn(500, function() {
                    $(this).delay(10000).fadeOut(500, function() {
                      $(this).delay(500).text('Willkommen im Kino').fadeIn(500, function() {
                        $(this).delay(10000).fadeOut(500, function() {
                          $(this).delay(500).text('Καλώς ήλθατε στο κίνημα').fadeIn(500, function() {
                            $(this).delay(10000).fadeOut(500, function() {
                              $(this).delay(500).text('Välkommen till Biografen').fadeIn(500, function() {
                                $(this).delay(10000).fadeOut(500, function() {
                                  $(this).delay(500).text('सिनेमा में आपका स्वागत है').fadeIn(500, function() {
                                    $(this).delay(10000).fadeOut(500, function() {
                                      $(this).delay(500).text('Welcome to the Cinema').fadeIn(500);
                                      cycle();
                                    });});});});});});});});});});});});});});});});});});});
}

//menu show/hide & video
$(document).ready(function(){

  $('.menu-button').on("click", function(){
      if($('.menu-button').hasClass('clicked')) {
          $(this).removeClass("clicked");
          $('.menu').css("right","-168");
      }
      else {
          $(this).addClass("clicked");
          $('.menu').css("right","0");
          if($('.menu-button').hasClass('clicked')) {
            $('.main-content').click(function(){
              $('.menu-button').removeClass("clicked");
              $('.menu').css("right","-168");
            });
          }
      }
  });

//film info show/hide
  $('.info-btn').on("click", function(){
      if($('.info-btn').hasClass('info-clicked')) {
          $(this).removeClass("info-clicked");
          $('.overlay-info').css("left","-250px");
          $('.info-btn').html('<i class="fa fa-angle-double-right info-btn-ct" aria-hidden="true"></i>');
      } else {
          $(this).addClass("info-clicked");
          $('.overlay-info').css("left","0px");
          $('.info-btn').html('<i class="fa fa-angle-double-left info-btn-ct" aria-hidden="true"></i>');
      }
  });

  $('.film-submit').click(function() {
    //console.log('first checkpoint hurray');
    var formData = new FormData($('.post-film')[0]);

    //console.log('copy');
    //console.log(formData);

  	jQuery.ajax({
  	 type: "POST",
  	 data: formData,
  	 url: "/update.php?type=post",
  	 cache: false,
     contentType: false,
     processData: false,
  	 success: function(response) {
       alert('success'); //will be fetching data/displaying (formdata?) to div on /submit
  	},
     error: function(response) {
       alert('failure'); // alert detailing error
     }
   });

  });

  /*$('.red').hover(
    function(){
      $('.left-cc').css("background-color","rgba(178,34,34,.65)");
      $('.red').css("border","0px solid #ccc");
    },
    function(){
      $('.left-cc').css("background-color","white");
      $('.red').css("border","1px solid #ccc");
    }
  );*/

  function infoHover(type) {

  }
  function filmmakerHover() {
    $('.filmmaker-txt').css("opacity:","1");
    $('.filmmaker-box').css("opacity:","1");

  }

});

$('.left-float').ready(function(){
  $('.info-bar').addClass('ready');
  console.log("hello");
});

//check size of window, correct menu position
$(window).resize(function(){
  if($(window).width() > "601") {
    $('.menu').css("left","0");
    $('.menu').css("right","");
  }
  else {
    $('.menu').css("right","-168");
    $('.menu').css("left","");
  }
});

/*

YOUTUBE OVERYLAY

*/

//calling functions

//onYouTubeIframeAPIReady('AXxnOOh8BMQ');

var player, playing = false;
var paused = true;

    function onPlayerStateChange(event) {
        if(!playing){

            $('.overlay-info').css("left","-250px");
            $('.overlay-info').css("z-index","0");

            if($(window).width() > 600) {
              $('.info-btn').css("left","0px");
            }
            else {
              $('.info-btn').css("left","-55px");
            }

            playing = true;
        }
    }

//quotes array
$(document).ready(function() {

var quotes = [
  "You're not wrong,<br>you're just an asshole.",
  "Damn!<br>We're in a tight spot.",
  "Of course it depends,<br>of course it depends.",
  //"There is no eleven,<br>you fucking whore!",
  "We must make an idol of our fear, and call it god.",
  "Is this it?<br>Is this really it?"
];

var source = [
  "The Dude",
  "Ulysses Everett McGill",
  "Zero",
  //"Church",
  "Antonius Block",
  "Kohayagawa Manbei"
];

var source_link = [
  "http://www.imdb.com/title/tt0118715/quotes/qt0464814?mavIsAdult=false",
  "http://www.imdb.com/title/tt0190590/quotes/qt0403999?mavIsAdult=false",
  "http://www.imdb.com/title/tt2278388/quotes/qt2214528?mavIsAdult=false",
  //"http://rvb.wikia.com/wiki/Baby_Steps",
  "http://www.imdb.com/title/tt0050976/quotes/qt0196765?mavIsAdult=false",
  "https://en.wikipedia.org/wiki/The_End_of_Summer"
]

var random = Math.floor((Math.random() * quotes.length));
//console.log(random);
$('.quote-text').html(quotes[random]);
$('.quote-author').html(source[random]);
$('.quote-source').attr('href',source_link[random]);

});



/**************************** / JS-PHP FUNCTIONS / ****************************/

// GRAVEYARD
// I NEVER CAN GET THESE MFKRS TO WORK

/*function post_film(title, summary, yt, img, d, p, w, dp, pd) {
	jQuery.ajax({
	type: "POST",
	data: {'title': title, 'summary': summary, 'yt':yt,
          'img': img, 'd': d, 'p': p, 'w': w, 'dp': dp, 'pd': pd},
	url: "/_admin/update.php?type=post",
	cache: false,
	success: function(response) {
		alert('success');
	}
	});
}*/

/*function post_note() {
  var title = document.getElementById("title").value;
  var date = Date.now();
  var content = document.getElementById("content").value;
  var dataString = 'title1=' + title + '&date1=' + date + '&content1=' + content;
	jQuery.ajax({
	type: "POST",
	url: "../notes/input.php?type=post_note",
  data: dataString,
	cache: false,
	success: function(response) {
		alert("success");
	}
	});
}*/

function loaded() {
  $('.loaded').text("loaded").delay(500).fadeOut(500);
}

$(document).ready(function(){

  //record player
  var records = [
    "dq.mp3",
    "tc.mp3",
    "wl.mp3"
  ];
  var random = Math.floor((Math.random() * records.length));


});











































/* hello */
