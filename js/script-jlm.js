/* theatre support */

function theatre_1(showtime, dur, filename, playerId) {
  playerId = playerId || 'motw';

  // guard: nothing scheduled
  if (!showtime || !filename) return;

  console.log('[th1] showtime:', showtime, '(' + new Date(showtime * 1000).toLocaleTimeString() + ')');
  console.log('[th1] dur:', dur, 'seconds (' + Math.floor(dur/60) + 'm)');
  console.log('[th1] filename:', filename);
  console.log('[th1] playerId:', playerId);

  // get or init the Video.js player for this element
  var player = videojs(playerId);

  player.ready(function() {
    var now = Date.now() / 1000;
    var diff = Math.floor(now - showtime);

    if (diff < 0) {
      // pre-show: don't expose the file — poster stays, reload interval handles the start
      console.log('[th1] pre-show (' + Math.abs(diff) + 's until showtime), not loading file');
      return;
    }

    if (diff >= dur) {
      // film already over
      console.log('[th1] film ended (diff >= dur)');
      return;
    }

    // set source via streaming endpoint (supports HTTP range requests for seeking)
    player.src({ type: 'video/mp4', src: '/motw/stream.php?f=' + encodeURIComponent(filename) });
    console.log('[th1] source set at diff=' + diff + 's, waiting for loadedmetadata...');

    player.one('loadedmetadata', function() {
      var now2 = Date.now() / 1000;
      var diff2 = Math.floor(now2 - showtime);
      console.log('[th1] loadedmetadata fired. seeking to', diff2 + 's');

      player.muted(true);
      player.currentTime(diff2);

      player.one('seeked', function() {
        console.log('[th1] seeked, now playing from', Math.floor(player.currentTime()) + 's');
        player.play().then(function() {
          console.log('[th1] muted autoplay succeeded');
          // show unmute nudge
          setTimeout(function() {
            if (player.muted()) {
              var btn = document.createElement('div');
              btn.id = 'unmute-nudge';
              btn.innerHTML = '&#128266; click to unmute';
              btn.style.cssText = 'position:absolute;bottom:55px;right:12px;background:rgba(0,0,0,0.65);color:#e2e2e2;font-size:44px;letter-spacing:4px;padding:20px 40px;cursor:pointer;z-index:9999;font-family:Montserrat,sans-serif;';
              var holdEl = document.getElementById(playerId + '-hold') || document.querySelector('.video-hold');
              if (holdEl) holdEl.appendChild(btn);
              btn.addEventListener('click', function() {
                player.muted(false);
                btn.remove();
              });
            }
          }, 500);
        }).catch(function(e) {
          player.muted(false);
          console.warn('[th1] autoplay blocked entirely:', e.message);
        });
      });
    });
  });

  // reload when showtime arrives (pre-show state)
  var nowCheck = Date.now() / 1000;
  if (nowCheck < showtime) {
    var c = setInterval(function() {
      if (Date.now() / 1000 >= showtime) {
        clearInterval(c);
        location.reload();
      }
    }, 5000);
  }

  // sync interval — every 3s, correct any drift beyond 2s
  var t = setInterval(function() {
    var now = Date.now() / 1000;
    var diff = Math.floor(now - showtime);

    if (diff < 0 || diff >= dur) return; // outside film bounds, do nothing

    var mct = Math.floor(player.currentTime());
    if (Math.abs(mct - diff) > 2) {
      player.currentTime(diff);
    }
  }, 3000);

  // snap back on any scrub attempt
  $('#' + playerId).on('mouseup touchend', function() {
    var now = Date.now() / 1000;
    var diff = Math.floor(now - showtime);
    if (diff >= 0 && diff < dur) {
      player.currentTime(diff);
    }
  });

  // status text
  function checkDur() {
    var now = Date.now() / 1000;
    if (now < showtime - 900) {
      $('.np').html('');
    } else if (now < showtime) {
      $('.np').html('<span style="color:orange;">starting soon</span>');
    } else if (now >= showtime && now < showtime + dur) {
      if (now > showtime + (dur / 3) * 2) {
        $('.np').html('<span style="color:orange;">almost over</span>');
      } else {
        $('.np').html('<span style="color:green;">playing now</span>');
      }
    } else {
      $('.np').html('<span style="color:#b22222;">ended</span>');
    }
  }

  checkDur();
  setInterval(checkDur, 60000);

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

  cycle();

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

// ── Custom player controls ──────────────────────────────────────────────

  function getPlayer($el) {
    var id = $el.closest('.player-controls').data('player');
    return videojs.players[id] || null;
  }

  function syncVolumeIcon($controls, player) {
    var $icon = $controls.find('.ctrl-mute i');
    if (player.muted() || player.volume() === 0) {
      $icon.attr('class', 'fa-solid fa-volume-xmark');
    } else if (player.volume() < 0.5) {
      $icon.attr('class', 'fa-solid fa-volume-low');
    } else {
      $icon.attr('class', 'fa-solid fa-volume-high');
    }
  }

  $(document).on('click', '.ctrl-mute', function() {
    var player = getPlayer($(this));
    if (!player) return;
    player.muted(!player.muted());
    syncVolumeIcon($(this).closest('.player-controls'), player);
  });

  $(document).on('input', '.ctrl-volume-slider', function() {
    var player = getPlayer($(this));
    if (!player) return;
    var vol = parseFloat($(this).val());
    player.volume(vol);
    player.muted(vol === 0);
    syncVolumeIcon($(this).closest('.player-controls'), player);
  });

  $(document).on('click', '.ctrl-fullscreen', function() {
    var player = getPlayer($(this));
    if (!player) return;
    var $icon = $(this).find('i');
    if (player.isFullscreen()) {
      player.exitFullscreen();
      $icon.attr('class', 'fa-solid fa-expand');
    } else {
      player.requestFullscreen();
      $icon.attr('class', 'fa-solid fa-compress');
    }
  });

  // ── motw theatre expansion
  var motwPlayerInited = false;

  $('#motw-banner').on('click', function(e) {
    if ($(e.target).closest('#motw-close').length) return;
    if ($(this).hasClass('expanded')) return;
    $(this).addClass('expanded');
    if (!motwPlayerInited && typeof motwShowtime !== 'undefined') {
      motwPlayerInited = true;
      theatre_1(motwShowtime, motwDur, motwFilename, 'motw-home');
    } else if (motwPlayerInited) {
      var p = videojs.players['motw-home'];
      if (p) p.play();
    }
  });

  $('#motw-close').on('click', function(e) {
    e.stopPropagation();
    $('#motw-banner').removeClass('expanded');
    var p = videojs.players['motw-home'];
    if (p) p.pause();
  });

// post panel — navigate to post page if data-url set, otherwise expand in-place
  $(document).on('click', '.post-panel:not(.active)', function() {
    var $panel = $(this);
    var url = $panel.data('url');
    if (url) { window.location.href = url; return; }
    var postId = $panel.data('post-id');
    $panel.addClass('active');

    var $content = $panel.find('.post-panel-content');
    if ($content.is(':empty')) {
      $content.html('<span style="color:#555;font-size:11px;letter-spacing:1px;font-family:Montserrat;">Loading...</span>');
      $.ajax({
        url: '/posts/get.php?id=' + postId,
        dataType: 'json',
        success: function(res) {
          if (!res.post) { $content.html(''); return; }
          var p = res.post;
          var html = '';
          if (p.subtitle) html += '<div class="pp-subtitle">' + $('<div>').text(p.subtitle).html() + '</div>';
          var meta = '';
          if (p.author_name) meta += $('<div>').text(p.author_name).html();
          if (p.stamp) {
            var d = new Date(p.stamp * 1000);
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            if (meta) meta += ' &middot; ';
            meta += months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
          }
          if (meta) html += '<div class="pp-meta">' + meta + '</div>';
          html += '<hr class="pp-divider">';
          if (p.content) html += p.content.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
          $content.html(html);
        }
      });
    }
  });
  $(document).on('click', function(e) {
    if (!$(e.target).closest('.post-panel').length) {
      $('.post-panel').removeClass('active');
    }
  });

// featured hero — smooth expand/collapse via scrollHeight measurement
  $('#featured-read-btn').on('click', function() {
    var $btn  = $(this);
    var $body = $('.featured-body');
    var $fade = $('.featured-fade');
    var body  = $body[0];

    if ($btn.hasClass('expanded')) {
      $body.css('max-height', body.scrollHeight + 'px'); // anchor from real height before collapsing
      requestAnimationFrame(function() {
        requestAnimationFrame(function() { // double rAF ensures browser registers the anchor first
          $body.css('max-height', '145px');
          $fade.css('opacity', '1');
        });
      });
      $btn.text('Read →').removeClass('expanded');
    } else {
      $body.css('max-height', body.scrollHeight + 'px');
      $fade.css('opacity', '0');
      $btn.text('Collapse ↑').addClass('expanded');
    }
  });

// quick post — type selector
  (function() {
    var $selector = $('#qp-selector');
    var $btn      = $('#qp-selector-btn');
    var $list     = $('#qp-selector-list');
    var $label    = $('#qp-current-label');
    if (!$selector.length) return;

    $btn.on('click', function(e) {
      e.stopPropagation();
      $selector.toggleClass('open');
    });

    $list.on('click', '.qp-option', function() {
      var type = $(this).data('type');
      $list.find('.qp-option').removeClass('active');
      $(this).addClass('active');
      $label.text($(this).text());
      $selector.removeClass('open');
      $('.qp-mode').removeClass('active').hide();
      $('#qp-mode-' + type).addClass('active').show();
    });

    $(document).on('click', function(e) {
      if (!$(e.target).closest('#qp-selector').length) {
        $selector.removeClass('open');
      }
    });
  })();

// quick post — submit
  $(document).on('click', '.qp-submit', function() {
    var type     = $(this).data('type');
    var $mode    = $('#qp-mode-' + type);
    var content  = $mode.find('.qp-textarea').val().trim();
    var title    = $mode.find('.qp-title-input').val().trim();
    var subtitle = $mode.find('.qp-subtitle-input').val().trim();
    var $btn     = $(this);

    if (!content && !title) { alert('Nothing to post.'); return; }

    $btn.text('...').prop('disabled', true);

    $.ajax({
      type: 'POST',
      url: '/posts/quick.php',
      data: { type: type, content: content, title: title, subtitle: subtitle },
      dataType: 'json',
      success: function(res) {
        if (res.success) {
          alert('Post submitted.');
          location.reload();
        } else {
          alert('Error: ' + (res.error || 'unknown'));
          $btn.text(type === 'post' ? 'Post' : 'Publish ' + type.charAt(0).toUpperCase() + type.slice(1)).prop('disabled', false);
        }
      },
      error: function() {
        alert('Server error. Please try again.');
        $btn.text(type === 'post' ? 'Post' : 'Publish ' + type.charAt(0).toUpperCase() + type.slice(1)).prop('disabled', false);
      }
    });
  });

// member overlay — open on entry click, close on backdrop/button
  $(document).on('click', '#community-panel .entry', function(e) {
    if ($(e.target).closest('i, .info-box, .info-box-list, .addremove, .hover-infoAdd').length) return;
    var $entry   = $(this);
    var name     = $entry.data('name')    || '';
    var dept     = $entry.data('dept')    || '';
    var email    = $entry.data('email')   || '';
    var phone    = $entry.data('phone')   || '';
    var website  = $entry.data('website') || '';
    var lb       = $entry.data('lb')      || '';
    var since    = $entry.data('since')   || '';

    var html = '<div class="mo-name">' + $('<span>').text(name).html() + '</div>';
    if (dept) html += '<div class="mo-dept">' + $('<span>').text(dept).html() + '</div>';
    html += '<div class="mo-divider"></div>';
    if (email)   html += '<div class="mo-row"><i class="fa-solid fa-envelope"></i> <a href="mailto:' + $('<span>').text(email).html() + '">' + $('<span>').text(email).html() + '</a></div>';
    if (phone)   html += '<div class="mo-row"><i class="fa-solid fa-phone"></i> <a href="tel:' + $('<span>').text(phone).html() + '">' + $('<span>').text(phone).html() + '</a></div>';
    if (website) html += '<div class="mo-row"><i class="fa-solid fa-globe"></i> <a href="' + $('<span>').text(website).html() + '" target="_blank" rel="noopener">' + $('<span>').text(website).html() + '</a></div>';
    if (lb)      html += '<div class="mo-row"><i class="fa-brands fa-letterboxd"></i> <a href="https://letterboxd.com/' + $('<span>').text(lb).html() + '" target="_blank" rel="noopener">' + $('<span>').text(lb).html() + '</a></div>';
    if (since) {
      var d = new Date(since);
      if (!isNaN(d)) {
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        html += '<div class="mo-since">Member since ' + months[d.getMonth()] + ' ' + d.getFullYear() + '</div>';
      }
    }

    $('#member-overlay-content').html(html);
    $('#member-overlay').addClass('active');
  });

  $('#member-overlay-close').on('click', function() {
    $('#member-overlay').removeClass('active');
  });

  $('#member-overlay').on('click', function(e) {
    if (!$(e.target).closest('#member-overlay-card').length) {
      $(this).removeClass('active');
    }
  });

// signup panel — open on click, close on outside click
  $('#signup-panel').on('click', function() {
    if (!$(this).hasClass('active')) {
      $(this).addClass('active');
    }
  });
  $(document).on('click', function(e) {
    if (!$(e.target).closest('#signup-panel').length) {
      $('#signup-panel').removeClass('active');
    }
  });

  // step 1 AJAX submit
  $('#signup-form-1').on('submit', function(e) {
    e.preventDefault();
    var $btn = $(this).find('[type=submit]');
    $btn.val('...').prop('disabled', true);

    $.ajax({
      type: 'POST',
      url: '/dashboard/signup.php?action=signup',
      data: $(this).serialize(),
      dataType: 'json',
      success: function(res) {
        if (res.success) {
          $('#signup-uid').val(res.uid);
          $('#signup-step-1').fadeOut(200, function() {
            $('#signup-step-2').fadeIn(200);
          });
        } else {
          var msgs = {
            '102': 'Please fill all fields and ensure passwords match.',
            '104': 'That email is already registered.',
            '108': 'Invalid access code.'
          };
          $('#signup-error').text(msgs[res.error] || 'An error occurred.').show();
          $btn.val('Sign up').prop('disabled', false);
        }
      },
      error: function() {
        $('#signup-error').text('Server error. Please try again.').show();
        $btn.val('Sign up').prop('disabled', false);
      }
    });
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
