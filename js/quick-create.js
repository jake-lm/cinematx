$(document).ready(function() {

  if (!$('#quick-create-sidebar').length) return; // logged out — nothing to wire

  var IDLE_MS = 5000;

  // ── Sidebar open/close ───────────────────────────────────────────────────

  var $sidebar  = $('#quick-create-sidebar');
  var $backdrop = $('#quick-create-backdrop');

  function openSidebar() {
    $sidebar.addClass('open');
    $backdrop.addClass('open');
  }

  function closeSidebar() {
    $sidebar.removeClass('open');
    $backdrop.removeClass('open');
  }

  $('#qcs-close').on('click', closeSidebar);
  $backdrop.on('click', closeSidebar);
  $(document).on('keydown', function(e) {
    if (e.key === 'Escape' && $sidebar.hasClass('open')) closeSidebar();
  });

  function setQcsStatus(msg, color) {
    $('#qcs-status').text(msg).css('color', color || '');
  }

  // ── Type selector ────────────────────────────────────────────────────────

  var qcsType = 'essay'; // 'essay' | 'review' | 'event'

  function setQcsType(type) {
    qcsType = type;
    $('#qcs-selector-list .qp-option').removeClass('active');
    $('#qcs-selector-list .qp-option[data-type="' + type + '"]').addClass('active');
    $('#qcs-current-label').text($('#qcs-selector-list .qp-option[data-type="' + type + '"]').text());
    $('.qcs-mode').removeClass('active');
    $('#qcs-mode-' + (type === 'event' ? 'event' : 'post')).addClass('active');
    updateQcsButtons();
  }

  $('#qcs-selector-btn').on('click', function(e) {
    e.stopPropagation();
    $('#qcs-selector').toggleClass('open');
  });
  $('#qcs-selector-list').on('click', '.qp-option', function() {
    setQcsType($(this).data('type'));
    $('#qcs-selector').removeClass('open');
  });
  $(document).on('click', function(e) {
    if (!$(e.target).closest('#qcs-selector').length) {
      $('#qcs-selector').removeClass('open');
    }
  });

  // ── Shared Save/Publish buttons — route to whichever mode is active ────────

  function updateQcsButtons() {
    var id, active;
    if (qcsType === 'event') { id = qcsEventId; active = qcsEventActive; }
    else                     { id = qcsPostId;  active = qcsPostActive; }

    if (id === null) {
      $('#qcs-save').text('Save Draft').prop('disabled', false);
      $('#qcs-publish').text('Publish').prop('disabled', true);
    } else if (active === 1) {
      $('#qcs-save').text('Save').prop('disabled', true);
      $('#qcs-publish').text('Published').prop('disabled', true);
    } else {
      $('#qcs-save').text('Save Draft').prop('disabled', false);
      $('#qcs-publish').text('Publish').prop('disabled', false);
    }
  }

  $('#qcs-save').on('click', function() {
    $(this).prop('disabled', true).text('Saving...');
    if (qcsType === 'event') saveQcsEvent(); else saveQcsPost();
  });
  $('#qcs-publish').on('click', function() {
    if (qcsType === 'event') publishQcsEvent(); else publishQcsPost();
  });

  // ── "+" trigger — always starts a fresh draft ───────────────────────────────

  $('#topbar-create-btn').on('click', function() {
    resetQcsPost();
    resetQcsEvent();
    setQcsType('essay');
    setQcsStatus('');
    openSidebar();
  });

  // ── Post (essay/review) — mirrors dashboard.js's Write panel ───────────────

  var qcsPostId         = null;
  var qcsPostActive     = 0;
  var qcsPostSaveTimer  = null;
  var qcsPostAutosaveOn = false;

  function qcsPostData() {
    return {
      title:      $('#qcs-post-title').val().trim(),
      subtitle:   $('#qcs-post-subtitle').val().trim(),
      content:    $('#qcs-post-content').val().trim(),
      type:       qcsType === 'review' ? 'review' : 'essay',
      photo_cred: $('#qcs-post-photo-cred').val().trim()
    };
  }

  function resetQcsPost() {
    qcsPostId = null; qcsPostActive = 0; qcsPostAutosaveOn = false;
    clearTimeout(qcsPostSaveTimer);
    $('#qcs-post-title, #qcs-post-subtitle, #qcs-post-content, #qcs-post-photo-cred').val('');
    $('#qcs-post-featured').prop('checked', false);
    clearQcsPostImagePreview();
    $('#qcs-post-image').prop('disabled', true);
    $('#qcs-post-image-label').removeClass('enabled');
    $('#qcs-post-image-hint').text('Save a draft first');
  }

  function enableQcsPostImage() {
    $('#qcs-post-image').prop('disabled', false);
    $('#qcs-post-image-label').addClass('enabled');
    $('#qcs-post-image-hint').text('');
  }
  function showQcsPostImagePreview(src) {
    $('#qcs-post-image-thumb').attr('src', src);
    $('#qcs-post-image-preview').show();
  }
  function clearQcsPostImagePreview() {
    $('#qcs-post-image-thumb').attr('src', '');
    $('#qcs-post-image-preview').hide();
    $('#qcs-post-image').val('');
  }

  function uploadQcsPostImage(file) {
    if (!qcsPostId || !file) return;
    var fd = new FormData();
    fd.append('post_id', qcsPostId);
    fd.append('image', file);
    setQcsStatus('uploading image...');
    $.ajax({
      type: 'POST', url: '/dashboard/post.php?action=upload_image',
      data: fd, dataType: 'json', processData: false, contentType: false,
      success: function(res) {
        if (res.success) setQcsStatus('image saved');
        else { setQcsStatus('image upload failed', '#b22222'); clearQcsPostImagePreview(); }
      },
      error: function() { setQcsStatus('image upload failed', '#b22222'); clearQcsPostImagePreview(); }
    });
  }

  $('#qcs-post-image').on('change', function() {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) { showQcsPostImagePreview(e.target.result); };
    reader.readAsDataURL(file);
    uploadQcsPostImage(file);
  });

  $('#qcs-post-image-remove').on('click', function() {
    clearQcsPostImagePreview();
    if (qcsPostId) {
      $.ajax({
        type: 'POST', url: '/dashboard/post.php?action=upload_image&remove=1',
        data: { post_id: qcsPostId }, dataType: 'json'
      });
    }
  });

  $('#qcs-post-featured').on('change', function() {
    if (!qcsPostId) { $(this).prop('checked', false); return; }
    var val = $(this).is(':checked') ? 1 : 0;
    $.ajax({
      type: 'POST', url: '/dashboard/post.php?action=feature',
      data: { post_id: qcsPostId, featured: val }, dataType: 'json',
      success: function(res) { setQcsStatus(res.success ? 'saved' : 'save failed', res.success ? '' : '#b22222'); }
    });
  });

  function saveQcsPost() {
    var d = qcsPostData();
    if (!d.title && !d.content) { $('#qcs-save').prop('disabled', false).text('Save Draft'); return; }

    setQcsStatus('saving...');

    if (qcsPostId === null) {
      $.ajax({
        type: 'POST', url: '/dashboard/post.php?action=create',
        data: d, dataType: 'json',
        success: function(res) {
          if (res.success) {
            qcsPostId = res.post_id;
            qcsPostActive = 0;
            qcsPostAutosaveOn = true;
            enableQcsPostImage();
            setQcsStatus('draft saved');
          } else {
            setQcsStatus('save failed', '#b22222');
          }
          updateQcsButtons();
        },
        error: function() { setQcsStatus('save failed', '#b22222'); updateQcsButtons(); }
      });
    } else {
      d.post_id = qcsPostId;
      $.ajax({
        type: 'POST', url: '/dashboard/post.php?action=update',
        data: d, dataType: 'json',
        success: function(res) {
          setQcsStatus(res.success ? 'saved' : 'save failed', res.success ? '' : '#b22222');
          updateQcsButtons();
        },
        error: function() { setQcsStatus('save failed', '#b22222'); updateQcsButtons(); }
      });
    }
  }

  $('#qcs-post-title, #qcs-post-subtitle, #qcs-post-content').on('input change', function() {
    if (!qcsPostAutosaveOn || qcsType === 'event') return;
    setQcsStatus('unsaved', '#888');
    clearTimeout(qcsPostSaveTimer);
    qcsPostSaveTimer = setTimeout(saveQcsPost, IDLE_MS);
  });

  function publishQcsPost() {
    if (!qcsPostId) return;
    $.ajax({
      type: 'POST', url: '/dashboard/post.php?action=publish',
      data: { post_id: qcsPostId }, dataType: 'json',
      success: function(res) {
        if (res.success) {
          qcsPostActive = 1;
          qcsPostAutosaveOn = false;
          setQcsStatus('published', '#5a9e6f');
          updateQcsButtons();
        }
      }
    });
  }

  function loadQcsPost(id) {
    $.ajax({
      type: 'GET', url: '/dashboard/post.php?action=get&post_id=' + id,
      dataType: 'json',
      success: function(res) {
        if (!res.success) return;
        var p = res.post;

        $('#qcs-post-title').val(p.title);
        $('#qcs-post-subtitle').val(p.subtitle || '');
        $('#qcs-post-content').val(p.content);
        $('#qcs-post-photo-cred').val(p.photo_cred || '');
        $('#qcs-post-featured').prop('checked', parseInt(p.featured) === 1);

        qcsPostId         = parseInt(id);
        qcsPostActive      = parseInt(p.active) === 1 ? 1 : 0;
        qcsPostAutosaveOn  = qcsPostActive === 0;
        clearTimeout(qcsPostSaveTimer);

        enableQcsPostImage();
        if (p.image) showQcsPostImagePreview('/uploads/posts/' + p.image);
        else clearQcsPostImagePreview();

        setQcsType(p.type === 'review' ? 'review' : 'essay');
        setQcsStatus(qcsPostActive === 1 ? 'editing live post' : 'draft loaded', qcsPostActive === 1 ? '#5a9e6f' : '');

        openSidebar();
      }
    });
  }

  // ── Event — mirrors dashboard.js's Events tab ───────────────────────────────

  var qcsEventId         = null;
  var qcsEventActive     = 0;
  var qcsEventSaveTimer  = null;
  var qcsEventAutosaveOn = false;

  function qcsEventData() {
    return {
      title:      $('#qcs-event-title').val().trim(),
      location:   $('#qcs-event-location').val().trim(),
      address:    $('#qcs-event-address').val().trim(),
      screentime: $('#qcs-event-screentime').val()
    };
  }

  function resetQcsEvent() {
    qcsEventId = null; qcsEventActive = 0; qcsEventAutosaveOn = false;
    clearTimeout(qcsEventSaveTimer);
    $('#qcs-event-title, #qcs-event-location, #qcs-event-address, #qcs-event-screentime').val('');
    clearQcsEventPosterPreview();
    $('#qcs-event-poster').prop('disabled', true);
    $('#qcs-event-poster-label').removeClass('enabled');
    $('#qcs-event-poster-hint').text('Save a draft first');
  }

  function enableQcsEventPoster() {
    $('#qcs-event-poster').prop('disabled', false);
    $('#qcs-event-poster-label').addClass('enabled');
    $('#qcs-event-poster-hint').text('');
  }
  function showQcsEventPosterPreview(src) {
    $('#qcs-event-poster-thumb').attr('src', src);
    $('#qcs-event-poster-preview').show();
  }
  function clearQcsEventPosterPreview() {
    $('#qcs-event-poster-thumb').attr('src', '');
    $('#qcs-event-poster-preview').hide();
    $('#qcs-event-poster').val('');
  }

  function uploadQcsEventPoster(file) {
    if (!qcsEventId || !file) return;
    var fd = new FormData();
    fd.append('event_id', qcsEventId);
    fd.append('poster', file);
    setQcsStatus('uploading poster...');
    $.ajax({
      type: 'POST', url: '/dashboard/event.php?action=upload_poster',
      data: fd, dataType: 'json', processData: false, contentType: false,
      success: function(res) {
        if (res.success) setQcsStatus('poster saved');
        else { setQcsStatus('poster upload failed', '#b22222'); clearQcsEventPosterPreview(); }
      },
      error: function() { setQcsStatus('poster upload failed', '#b22222'); clearQcsEventPosterPreview(); }
    });
  }

  $('#qcs-event-poster').on('change', function() {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) { showQcsEventPosterPreview(e.target.result); };
    reader.readAsDataURL(file);
    uploadQcsEventPoster(file);
  });

  $('#qcs-event-poster-remove').on('click', function() {
    clearQcsEventPosterPreview();
    if (qcsEventId) {
      $.ajax({
        type: 'POST', url: '/dashboard/event.php?action=upload_poster&remove=1',
        data: { event_id: qcsEventId }, dataType: 'json'
      });
    }
  });

  function saveQcsEvent() {
    var d = qcsEventData();
    if (!d.title && !d.location && !d.screentime) { $('#qcs-save').prop('disabled', false).text('Save Draft'); return; }

    setQcsStatus('saving...');

    if (qcsEventId === null) {
      $.ajax({
        type: 'POST', url: '/dashboard/event.php?action=create',
        data: d, dataType: 'json',
        success: function(res) {
          if (res.success) {
            qcsEventId = res.event_id;
            qcsEventActive = 0;
            qcsEventAutosaveOn = true;
            enableQcsEventPoster();
            setQcsStatus('draft saved');
          } else {
            setQcsStatus('save failed', '#b22222');
          }
          updateQcsButtons();
        },
        error: function() { setQcsStatus('save failed', '#b22222'); updateQcsButtons(); }
      });
    } else {
      d.event_id = qcsEventId;
      $.ajax({
        type: 'POST', url: '/dashboard/event.php?action=update',
        data: d, dataType: 'json',
        success: function(res) {
          setQcsStatus(res.success ? 'saved' : 'save failed', res.success ? '' : '#b22222');
          updateQcsButtons();
        },
        error: function() { setQcsStatus('save failed', '#b22222'); updateQcsButtons(); }
      });
    }
  }

  $('#qcs-event-title, #qcs-event-location, #qcs-event-address, #qcs-event-screentime').on('input change', function() {
    if (!qcsEventAutosaveOn || qcsType !== 'event') return;
    setQcsStatus('unsaved', '#888');
    clearTimeout(qcsEventSaveTimer);
    qcsEventSaveTimer = setTimeout(saveQcsEvent, IDLE_MS);
  });

  function publishQcsEvent() {
    if (!qcsEventId) return;
    $.ajax({
      type: 'POST', url: '/dashboard/event.php?action=publish',
      data: { event_id: qcsEventId }, dataType: 'json',
      success: function(res) {
        if (res.success) {
          qcsEventActive = 1;
          qcsEventAutosaveOn = false;
          setQcsStatus('published', '#5a9e6f');
          updateQcsButtons();
        }
      }
    });
  }

  function loadQcsEvent(id) {
    $.ajax({
      type: 'GET', url: '/dashboard/event.php?action=get&event_id=' + id,
      dataType: 'json',
      success: function(res) {
        if (!res.success) return;
        var ev = res.event;

        $('#qcs-event-title').val(ev.title);
        $('#qcs-event-location').val(ev.location);
        $('#qcs-event-address').val(ev.address || '');
        $('#qcs-event-screentime').val(ev.screentime_local);

        qcsEventId         = parseInt(id);
        qcsEventActive      = parseInt(ev.active) === 1 ? 1 : 0;
        qcsEventAutosaveOn  = qcsEventActive === 0;
        clearTimeout(qcsEventSaveTimer);

        enableQcsEventPoster();
        if (ev.poster) showQcsEventPosterPreview('/uploads/events/' + ev.poster);
        else clearQcsEventPosterPreview();

        setQcsType('event');
        setQcsStatus(qcsEventActive === 1 ? 'editing live screening' : 'draft loaded', qcsEventActive === 1 ? '#5a9e6f' : '');

        openSidebar();
      }
    });
  }

  // ── Recent activity dropdown ────────────────────────────────────────────────

  var $recentBtn      = $('#topbar-recent-btn');
  var $recentDropdown = $('#topbar-recent-dropdown');

  function renderRecentList(items) {
    if (!items.length) {
      $recentDropdown.html('<div class="topbar-recent-empty">Nothing yet.</div>');
      return;
    }
    var html = items.map(function(it) {
      var statusClass = it.status === 'live' ? 'status-live' : 'status-draft';
      return (
        '<div class="topbar-recent-item" data-kind="' + it.kind + '" data-id="' + it.id + '">' +
          '<span class="topbar-recent-title">' + $('<div>').text(it.title).html() + '</span>' +
          '<span class="topbar-recent-row2">' +
            '<span class="post-status ' + statusClass + '">' + it.status + '</span>' +
            '<span class="topbar-recent-sub">' + $('<div>').text(it.subtitle).html() + '</span>' +
            '<span class="topbar-recent-date">' + it.date + '</span>' +
          '</span>' +
        '</div>'
      );
    }).join('');
    $recentDropdown.html(html);
  }

  $recentBtn.on('click', function(e) {
    e.stopPropagation();
    if ($recentDropdown.hasClass('open')) {
      $recentDropdown.removeClass('open');
      return;
    }
    $recentDropdown.addClass('open').html('<div class="topbar-recent-empty">Loading...</div>');
    $.ajax({
      type: 'GET', url: '/dashboard/recent.php?action=list', dataType: 'json',
      success: function(res) {
        if (res.success) renderRecentList(res.items);
        else $recentDropdown.html('<div class="topbar-recent-empty">Couldn’t load.</div>');
      },
      error: function() { $recentDropdown.html('<div class="topbar-recent-empty">Couldn’t load.</div>'); }
    });
  });

  $(document).on('click', function(e) {
    if (!$(e.target).closest('.topbar-recent-wrap').length) {
      $recentDropdown.removeClass('open');
    }
  });

  $recentDropdown.on('click', '.topbar-recent-item', function() {
    var kind = $(this).data('kind');
    var id   = $(this).data('id');
    $recentDropdown.removeClass('open');
    if (kind === 'event') loadQcsEvent(id);
    else loadQcsPost(id);
  });

});
