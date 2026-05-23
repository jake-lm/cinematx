/*function copyToClip(text) {
  alert('hello');

  navigator.clipboard.writeText(text);

  alert("Copied the text: " + text);
}*/

function loadMore() { // load more entires
  var limit = $('.entry').length;
  var option = $('#loadsort option:selected').attr("name");
  var dept = $('#loadsort option:selected').attr("value");
  var search = $('.list_search').val();

  if(search != "" || search != null) {

  }

  jQuery.ajax({
  type: "POST",
  data: {'limit': limit, 'option': option, 'dept': dept, 'search': search},
  url: "function.php?action=loadmore",
  async: false,
  success: function(response) {
    var save = $('#reContain').html();
    $('#reContain').html(save + response);
    $('.loadmoretxt').Removeattr('disabled');
  }
  });
}

function loadSort() { // sort & load entries
  var option = $('#loadsort option:selected').text();
  //var limit = $('.entry').length;

  if(option === "Most Active") {
    //alert('test');
    $('.loadmoretxt').attr('disabled');
    //$('.loadmoretxt').text('loading...');
    jQuery.ajax({
    type: "POST",
    url: "function.php?action=mostactive",
    async: false,
    success: function(response) {
      $('.list_entry').html(response);
      $('#reContain').html("");
      $('.loadmoretxt').Removeattr('disabled');
      $('.loadmoretxt').text('Load more');
    }
    });
  }
  else if(option === "My Dept") {
    $('.loadmoretxt').attr('disabled');
    //$('.loadmoretxt').text('loading...');
    var dept = $('#loadsort option:selected').attr("value");
    jQuery.ajax({
    type: "POST",
    data: {'dept': dept},
    url: "function.php?action=mydept",
    async: false,
    success: function(response) {
      $('.list_entry').html(response);
      $('#reContain').html("");
      $('.loadmoretxt').Removeattr('disabled');
      $('.loadmoretxt').text('Load more');
    }
    });
  }
  else if(option === "My List") {
    $('.loadmoretxt').attr('disabled');
    //$('.loadmoretxt').text('loading...');
    var dept = $('#loadsort option:selected').attr("value");
    jQuery.ajax({
    type: "POST",
    data: {'dept': dept},
    url: "function.php?action=mylist",
    async: false,
    success: function(response) {
      $('.list_entry').html(response);
      $('#reContain').html("");
      $('.loadmoretxt').Removeattr('disabled');
      $('.loadmoretxt').text('Load more');
    }
    });
  }
  else {
    $('.loadmoretxt').attr('disabled');
    //$('.loadmoretxt').text('loading...');
    jQuery.ajax({
    type: "POST",
    url: "function.php?action=new",
    async: false,
    success: function(response) {
      $('.list_entry').html(response);
      $('#reContain').html("");
      $('.loadmoretxt').Removeattr('disabled');
      $('.loadmoretxt').text('Load more');
    }
    });
  }
}

function list_search() { // search & load entries
  var search = $('.list_search').val();

  if(search != "" || search != null) {
  jQuery.ajax({
  type: "POST",
  data: {'search': search},
  url: "function.php?action=listsearch",
  async: false,
  success: function(response) {
    var save = $('#reContain').html();
    $('.list_entry').html(response);
    $('#reContain').html("");
    $('.loadmoretxt').Removeattr('disabled');
  }
  });
  }
  else {
    jQuery.ajax({
    type: "POST",
    data: {'search': search},
    url: "function.php?action=reload",
    async: false,
    success: function(response) {
      var save = $('#reContain').html();
      $('.list_entry').html(response);
      $('#reContain').html("");
    }
    });
  }
}

function addTo(uid, fid) { // add to mylist

  jQuery.ajax({
  type: "POST",
  data: {'uid': uid, 'fid': fid},
  url: "function.php?action=addto",
  async: false,
  success: function(response) {
    $('.addremove'+uid).html(
      `<i class="fa-solid fa-minus remove hover-infoAdd" title="remove from my list" onclick="removeFrom(`+uid+`,`+fid+`)">
      <span class="info-boxAdd">remove from list</span>
      </i>`
    );
  }
  });
}

function removeFrom(uid, fid) { // remove from mylist

  jQuery.ajax({
  type: "POST",
  data: {'uid': uid, 'fid': fid},
  url: "function.php?action=removefrom",
  async: false,
  success: function(response) {
    $('.addremove'+uid).html(
      `<i class="fa-solid fa-plus addto hover-infoAdd" title="add to my list" onclick="addTo(`+uid+`,`+fid+`)">
      <span class="info-boxAdd">add to list</span>
      </i>`
    );
  }
  });
}

$(document).ready(function() { // menu support

  $('.menu-btn').on("click", function(){
      if($('.menu-btn').hasClass('clicked')) {
          $(this).removeClass("clicked");
          $('.menu').css("right","-200");
      }
      else {
          $(this).addClass("clicked");
          $('.menu').css("right","0");
      }
  });

  $('.home-base').click(function() {
    if($('.menu-btn').hasClass('clicked')) {
      $('.menu').css("right","-200");
      $('.menu-btn').removeClass("clicked");
    }
  });

});


// welcome message
function cycle() {
$('.welcome').delay(10000).fadeOut(500, function() {
  $(this).delay(500).text('Willkommen im Kino').fadeIn(500, function(){
    $(this).delay(10000).fadeOut(500, function() {
      $(this).delay(500).text('Добро пожаловать в кино').fadeIn(500, function() {
        $(this).delay(10000).fadeOut(500, function() {
          $(this).delay(500).text('劇場へようこそ').fadeIn(500, function() {
            $(this).delay(10000).fadeOut(500, function() {
              $(this).delay(500).text('쇼에 오신 걸 환영합니다').fadeIn(500, function() {
                $(this).delay(10000).fadeOut(500, function() {
                  $(this).delay(500).text('Bienvenue au Cinema').fadeIn(500, function() {
                    $(this).delay(10000).fadeOut(500, function() {
                      $(this).delay(500).text('Καλώς ήλθατε στο κίνημα').fadeIn(500, function() {
                        $(this).delay(10000).fadeOut(500, function() {
                          $(this).delay(500).text('Välkommen till Biografen').fadeIn(500, function() {
                            $(this).delay(10000).fadeOut(500, function() {
                              $(this).delay(500).text('सिनेमा में आपका स्वागत है').fadeIn(500, function() {
                                $(this).delay(10000).fadeOut(500, function() {
                                  $(this).delay(500).text('Welcome to the Cinema').fadeIn(500);
                                    cycle();
                                    });});});});});});});});});});});});});});});});});
}




$(document).ready(function () {
  $("#uploadForm").on("submit", function (event) {
      event.preventDefault();

      const formData = new FormData(this);
      const xhr = new XMLHttpRequest();
      let startTime = null;

      xhr.open("POST", "upload.php", true);

      xhr.upload.onprogress = function (event) {
          const progress = $("#uploadProgress");
          const estimatedTimeElement = $("#estimatedTime");

          if (event.lengthComputable) {
              const percentComplete = (event.loaded / event.total) * 100;
              progress.val(percentComplete);

              if (startTime === null) {
                  startTime = new Date();
              } else {
                  const currentTime = new Date();
                  const elapsedTime = (currentTime - startTime) / 1000; // Time elapsed in seconds
                  const timeRemaining = (elapsedTime * (100 - percentComplete)) / percentComplete;

                  const minutes = Math.floor(timeRemaining / 60);
                  const seconds = Math.floor(timeRemaining % 60);

                  estimatedTimeElement.text(`Estimated time remaining: ${minutes}m ${seconds}s`);
              }
          } else {
              progress.removeAttr("value");
          }
      };

      xhr.onload = function () {
          if (xhr.status === 200) {
              alert("Files uploaded successfully.");
          } else {
              alert("File upload failed.");
          }
      };

      xhr.send(formData);
  });
});

$(document).ready(function () {
  const bannerElement = $("#dynamicBanner");

  // Get the background image URL
  const backgroundImageUrl = bannerElement.css("background-image").replace(/url\(['"]?(.*?)['"]?\)/i, "$1");

  // Create a temporary image to get the dimensions
  const tempImage = new Image();
  tempImage.src = backgroundImageUrl;

  tempImage.onload = function () {
      const originalWidth = tempImage.width;
      const originalHeight = tempImage.height;

      // Calculate the banner height based on the original image's aspect ratio
      const bannerWidth = bannerElement.width();
      const bannerHeight = (bannerWidth * originalHeight) / originalWidth;

      // Set the banner height
      bannerElement.height(bannerHeight);
  };
});

let videoElement = document.getElementById('motw');

// Listen for the fullscreenchange event
/*document.addEventListener('fullscreenchange', () => {
  // Check if in fullscreen mode
  if (document.fullscreenElement) {
    // If in fullscreen mode, remove any size restrictions
    videoElement.style.maxWidth = 'none';
    videoElement.style.maxHeight = 'none';
    videoElement.style.width = '100%';
    videoElement.style.height = '100%';
  } else {
    // If not in fullscreen mode, apply your normal dimensions
    videoElement.style.maxWidth = '640';
    videoElement.style.maxHeight = '360';
    videoElement.style.width = '640';
    videoElement.style.height = '360';
  }
});*/

$(document).ready(function() {
  // Function to calculate font size based on string length
  function calculateFontSize(title) {
    var length = title.length;
    var fontSize = 24;

    if (length <= 10) {
      fontSize = 18;
    } else if (length <= 20) {
      fontSize = 16;
    } else if (length <= 30) {
      fontSize = 14;
    } else if (length <= 40) {
      fontSize = 12;
    } else if (length <= 50) {
      fontSize = 10;
    } else {
      fontSize = 16;
    }

    return fontSize;
  }

  // Get the current title
  var currentTitle = $('#film-title').text();
  // Calculate the font size
  var fontSize = calculateFontSize(currentTitle);
  // Set the font size
  $('#film-title').css('font-size', fontSize + 'px');
});

































// fuck off
