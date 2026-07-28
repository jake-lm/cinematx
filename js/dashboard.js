$(document).ready(function() {

  // ── Tab navigation ─────────────────────────────────────────────────────────

  function activateTab(name) {
    $('.dash-tab').removeClass('active');
    $('.dash-tab[data-panel="' + name + '"]').addClass('active');
    $('.dash-panel').hide();
    $('#panel-' + name).fadeIn(150);
    location.hash = name;
  }

  // honour URL hash on load, default to 'write'
  var initTab = location.hash.replace('#', '');
  activateTab(['write', 'posts', 'events', 'account'].indexOf(initTab) !== -1 ? initTab : 'write');

  $('.dash-tab').on('click', function() {
    activateTab($(this).data('panel'));
  });


  // ── Autosave ───────────────────────────────────────────────────────────────

  var postId      = null;
  var saveTimer   = null;
  var autosaveOn  = false;
  var IDLE_MS     = 5000;

  function postData() {
    return {
      title:       $('#post-title').val().trim(),
      subtitle:    $('#post-subtitle').val().trim(),
      content:     $('#post-content').val().trim(),
      type:        $('#post-type').val(),
      photo_cred:  $('#post-photo-cred-1').val().trim(),
      photo_cred2: $('#post-photo-cred-2').val().trim(),
      photo_cred3: $('#post-photo-cred-3').val().trim(),
      image_mode:  $('#post-image-mode').val()
    };
  }

  function setStatus(msg, color) {
    $('#autosave-status').text(msg).css('color', color || '');
  }

  // ── Image upload ───────────────────────────────────────────────────────────
  // Three independent slots (1 hero, 2 and 3 for the inline/cycle spread),
  // same endpoint as before with a slot number now telling it which column.

  function enableImageInput(n) {
    $('#post-image-' + n).prop('disabled', false);
    $('#post-image-label-' + n).addClass('enabled');
    $('#post-image-hint-' + n).text('');
  }

  function showImagePreview(n, src) {
    $('#post-image-thumb-' + n).attr('src', src);
    $('#post-image-preview-' + n).show();
  }

  function clearImagePreview(n) {
    $('#post-image-thumb-' + n).attr('src', '');
    $('#post-image-preview-' + n).hide();
    $('#post-image-' + n).val('');
  }

  function uploadImage(n, file) {
    if (!postId || !file) return;
    var fd = new FormData();
    fd.append('post_id', postId);
    fd.append('slot', n);
    fd.append('image', file);
    setStatus('uploading image...');
    $.ajax({
      type: 'POST', url: '/dashboard/post.php?action=upload_image',
      data: fd, dataType: 'json',
      processData: false, contentType: false,
      success: function(res) {
        if (res.success) {
          setStatus('image saved');
        } else {
          // The endpoint distinguishes not_found, invalid_type, too_large,
          // move_failed and upload_error. Collapsing all five into one message
          // made a directory-permissions problem and a published-post problem
          // look identical from the outside.
          setStatus('image upload failed' + (res && res.error ? ' (' + res.error + ')' : ''), '#b22222');
          clearImagePreview(n);
        }
      },
      error: function() {
        setStatus('image upload failed', '#b22222');
        clearImagePreview(n);
      }
    });
  }

  [1, 2, 3].forEach(function (n) {
    $('#post-image-' + n).on('change', function() {
      var file = this.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function(e) { showImagePreview(n, e.target.result); };
      reader.readAsDataURL(file);
      uploadImage(n, file);
    });

    $('#post-image-remove-' + n).on('click', function() {
      clearImagePreview(n);
      // clear image in DB via update with empty image flag
      if (postId) {
        $.ajax({
          type: 'POST', url: '/dashboard/post.php?action=upload_image&remove=1&slot=' + n,
          data: { post_id: postId }, dataType: 'json'
        });
      }
    });
  });

  // ── Profile photo upload (Account tab) ──────────────────────────────────────

  $('#profile-photo-input').on('change', function() {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) {
      $('#profile-photo-preview-img').attr('src', e.target.result).show();
      $('#profile-photo-placeholder').hide();
    };
    reader.readAsDataURL(file);

    var fd = new FormData();
    fd.append('photo', file);
    $.ajax({
      type: 'POST', url: '/dashboard/profile.php?action=upload_photo',
      data: fd, dataType: 'json',
      processData: false, contentType: false,
      success: function(res) {
        if (res.success) {
          $('#profile-photo-remove').show();
        } else {
          alert('Photo upload failed.');
        }
      },
      error: function() { alert('Photo upload failed.'); }
    });
  });

  $('#profile-photo-remove').on('click', function() {
    $('#profile-photo-preview-img').hide().attr('src', '');
    $('#profile-photo-placeholder').show();
    $(this).hide();
    $.ajax({
      type: 'POST', url: '/dashboard/profile.php?action=upload_photo&remove=1',
      data: {}, dataType: 'json'
    });
  });

  // ── Save ───────────────────────────────────────────────────────────────────

  function save() {
    var d = postData();
    if (!d.title && !d.content) return;

    setStatus('saving...');

    if (postId === null) {
      $.ajax({
        type: 'POST', url: '/dashboard/post.php?action=create',
        data: d, dataType: 'json',
        success: function(res) {
          if (res.success) {
            postId = res.post_id;
            autosaveOn = true;
            [1, 2, 3].forEach(enableImageInput);
            $('#post-publish').prop('disabled', false);
            $('#post-save').text('Save Draft');
            setStatus('draft saved');
          } else {
            setStatus('save failed', '#b22222');
            $('#post-save').prop('disabled', false).text('Save Draft');
          }
        },
        error: function() {
          setStatus('save failed', '#b22222');
          $('#post-save').prop('disabled', false).text('Save Draft');
        }
      });
    } else {
      d.post_id = postId;
      $.ajax({
        type: 'POST', url: '/dashboard/post.php?action=update',
        data: d, dataType: 'json',
        success: function(res) {
          setStatus(res.success ? 'saved' : 'save failed', res.success ? '' : '#b22222');
        },
        error: function() { setStatus('save failed', '#b22222'); }
      });
    }
  }

  // autosave only fires after the first manual save
  $('#post-title, #post-subtitle, #post-content, #post-type').on('input change', function() {
    if (!autosaveOn) return;
    setStatus('unsaved', '#888');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, IDLE_MS);
  });

  // ── Save Draft (manual trigger) ────────────────────────────────────────────

  $('#post-save').on('click', function() {
    $(this).prop('disabled', true).text('Saving...');
    save();
  });


  // ── Load draft into editor ─────────────────────────────────────────────────

  // ── Post row state helpers ─────────────────────────────────────────────────

  function slugify(text) {
    return (text || '').toLowerCase().trim()
      .replace(/[^a-z0-9\s-]/g, '')
      .replace(/[\s-]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  function setRowLive($row) {
    var id   = $row.data('id');
    var slug = slugify($row.data('title'));
    $row.attr('data-active', '1').addClass('post-live');
    $row.find('.post-status').attr('class', 'post-status status-live').text('live');
    $row.find('.post-row-actions').html(
      '<a class="post-view" href="/posts/?id=' + id + '" target="_blank">view</a>' +
      '<button class="post-edit" data-id="' + id + '">edit</button>' +
      '<button class="post-unpublish" data-id="' + id + '">unpublish</button>'
    );
  }

  function setRowDraft($row) {
    $row.attr('data-active', '0').removeClass('post-live');
    $row.find('.post-status').attr('class', 'post-status status-draft').text('draft');
    $row.find('.post-row-actions').html(
      '<button class="draft-delete" data-id="' + $row.data('id') + '">&#x2715;</button>'
    );
  }

  // ── Load post into editor (draft row click or edit button) ────────────────

  function loadPost(id) {
    $.ajax({
      type: 'GET', url: '/dashboard/post.php?action=get&post_id=' + id,
      dataType: 'json',
      success: function(res) {
        if (!res.success) return;

        $('#post-title').val(res.post.title);
        $('#post-subtitle').val(res.post.subtitle || '');
        $('#post-type').val(res.post.type || '');
        $('#post-content').val(res.post.content);
        $('#post-photo-cred-1').val(res.post.photo_cred || '');
        $('#post-photo-cred-2').val(res.post.photo_cred2 || '');
        $('#post-photo-cred-3').val(res.post.photo_cred3 || '');
        $('#post-image-mode').val(res.post.image_mode || 'cycle');

        postId     = parseInt(id);
        autosaveOn = true;
        clearTimeout(saveTimer);

        var images = [res.post.image, res.post.image2, res.post.image3];
        [1, 2, 3].forEach(function (n) {
          enableImageInput(n);
          if (images[n - 1]) {
            showImagePreview(n, '/uploads/posts/' + images[n - 1]);
          } else {
            clearImagePreview(n);
          }
        });

        $('#post-featured').prop('checked', parseInt(res.post.featured) === 1);
        $('#post-save').prop('disabled', false).text('Save Draft');

        if (parseInt(res.post.active) === 1) {
          $('#post-save').text('Save');
          $('#post-publish').prop('disabled', true).text('Published');
          setStatus('editing live post', '#5a9e6f');
        } else {
          $('#post-save').text('Save Draft');
          $('#post-publish').prop('disabled', false).text('Publish');
          setStatus('draft loaded');
        }

        activateTab('write');
      }
    });
  }

  $(document).on('click', '.post-edit', function(e) {
    e.stopPropagation();
    loadPost($(this).data('id'));
  });

  $(document).on('click', '.draft-row', function(e) {
    if ($(this).hasClass('event-row')) return; // event rows share this class for styling only
    if ($(e.target).closest('.draft-delete, .post-unpublish, .post-edit, .draft-confirm').length) return;
    // only load drafts via row click — published rows use the edit button
    if ($(this).attr('data-active') === '1') return;

    loadPost($(this).data('id'));
  });

  // ── Delete draft (with inline confirmation) ────────────────────────────────

  function cancelConfirm($row) {
    $row.find('.draft-confirm').remove();
    $row.find('.draft-delete').show();
  }

  $(document).on('click', '.draft-delete', function(e) {
    e.stopPropagation();
    var $row = $(this).closest('.draft-row');

    // don't open a second confirm if one's already showing
    if ($row.find('.draft-confirm').length) return;

    $(this).hide();
    $row.append(
      '<span class="draft-confirm">' +
        'delete? ' +
        '<span class="draft-confirm-yes">yes</span>' +
        ' / ' +
        '<span class="draft-confirm-no">no</span>' +
      '</span>'
    );
  });

  $(document).on('click', '.draft-confirm-no', function(e) {
    if ($(this).closest('.event-row').length) return; // events have their own confirm handler
    e.stopPropagation();
    cancelConfirm($(this).closest('.draft-row'));
  });

  $(document).on('click', '.draft-confirm-yes', function(e) {
    if ($(this).closest('.event-row').length) return; // events have their own confirm handler
    e.stopPropagation();
    var $row = $(this).closest('.draft-row');
    var id   = $row.data('id');

    $.ajax({
      type: 'POST', url: '/dashboard/post.php?action=delete',
      data: { post_id: id }, dataType: 'json',
      success: function(res) {
        if (!res.success) return;
        $row.fadeOut(200, function() {
          $(this).remove();
          if ($('.draft-row').length === 0) {
            $('.drafts-list').replaceWith('<p class="drafts-empty">Nothing saved yet.</p>');
          }
        });
        if (postId === id) {
          postId = null; autosaveOn = false; clearTimeout(saveTimer);
          $('#post-title, #post-subtitle, #post-content, #post-photo-cred-1, #post-photo-cred-2, #post-photo-cred-3').val('');
          $('#post-type').val('');
          $('#post-image-mode').val('cycle');
          $('#post-featured').prop('checked', false);
          $('#post-publish').prop('disabled', true).text('Publish');
          $('#post-save').prop('disabled', false).text('Save Draft');
          [1, 2, 3].forEach(function (n) {
            clearImagePreview(n);
            $('#post-image-' + n).prop('disabled', true);
            $('#post-image-label-' + n).removeClass('enabled');
            $('#post-image-hint-' + n).text('Save a draft first');
          });
          setStatus('');
        }
      }
    });
  });

  // clicking outside a row with an open confirm cancels it
  $(document).on('click', function(e) {
    if (!$(e.target).closest('.draft-row').length) {
      $('.draft-row').each(function() { cancelConfirm($(this)); });
    }
  });

  // ── Unpublish ──────────────────────────────────────────────────────────────

  $(document).on('click', '.post-unpublish', function(e) {
    e.stopPropagation();
    var $row = $(this).closest('.draft-row');
    var id   = parseInt($row.data('id'));
    $.ajax({
      type: 'POST', url: '/dashboard/post.php?action=unpublish',
      data: { post_id: id }, dataType: 'json',
      success: function(res) {
        if (res.success) setRowDraft($row);
      }
    });
  });

  // ── Featured toggle ────────────────────────────────────────────────────────

  $('#post-featured').on('change', function() {
    if (!postId) { $(this).prop('checked', false); return; }
    var val = $(this).is(':checked') ? 1 : 0;
    $.ajax({
      type: 'POST', url: '/dashboard/post.php?action=feature',
      data: { post_id: postId, featured: val }, dataType: 'json',
      success: function(res) { setStatus(res.success ? 'saved' : 'save failed', res.success ? '' : '#b22222'); }
    });
  });

  // ── Publish ────────────────────────────────────────────────────────────────

  $('#post-publish').on('click', function() {
    if (!postId) return;
    var pid = postId;
    $.ajax({
      type: 'POST', url: '/dashboard/post.php?action=publish',
      data: { post_id: pid }, dataType: 'json',
      success: function(res) {
        if (res.success) {
          setStatus('published', '#5a9e6f');
          $('#post-publish').prop('disabled', true).text('Published');
          $('#post-save').prop('disabled', true);
          autosaveOn = false;
          // flip the row in the Posts tab if it exists
          var $row = $('.draft-row[data-id="' + pid + '"]');
          if ($row.length) setRowLive($row);
        }
      }
    });
  });

  // ── Events ───────────────────────────────────────────────────────────────

  var eventId         = null;
  var eventSaveTimer  = null;
  var eventAutosaveOn = false;

  function eventData() {
    return {
      title:      $('#event-title').val().trim(),
      location:   $('#event-location').val().trim(),
      address:    $('#event-address').val().trim(),
      screentime: $('#event-screentime').val()
    };
  }

  function setEventStatus(msg, color) {
    $('#event-autosave-status').text(msg).css('color', color || '');
  }

  // ── Event poster upload ──────────────────────────────────────────────────

  function enableEventPosterInput() {
    $('#event-poster').prop('disabled', false);
    $('#event-poster-label').addClass('enabled');
    $('#event-poster-hint').text('');
  }

  function showEventPosterPreview(src) {
    $('#event-poster-thumb').attr('src', src);
    $('#event-poster-preview').show();
  }

  function clearEventPosterPreview() {
    $('#event-poster-thumb').attr('src', '');
    $('#event-poster-preview').hide();
    $('#event-poster').val('');
  }

  function uploadEventPoster(file) {
    if (!eventId || !file) return;
    var fd = new FormData();
    fd.append('event_id', eventId);
    fd.append('poster', file);
    setEventStatus('uploading poster...');
    $.ajax({
      type: 'POST', url: '/dashboard/event.php?action=upload_poster',
      data: fd, dataType: 'json',
      processData: false, contentType: false,
      success: function(res) {
        if (res.success) {
          setEventStatus('poster saved');
        } else {
          setEventStatus('poster upload failed', '#b22222');
          clearEventPosterPreview();
        }
      },
      error: function() {
        setEventStatus('poster upload failed', '#b22222');
        clearEventPosterPreview();
      }
    });
  }

  $('#event-poster').on('change', function() {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) { showEventPosterPreview(e.target.result); };
    reader.readAsDataURL(file);
    uploadEventPoster(file);
  });

  $('#event-poster-remove').on('click', function() {
    clearEventPosterPreview();
    if (eventId) {
      $.ajax({
        type: 'POST', url: '/dashboard/event.php?action=upload_poster&remove=1',
        data: { event_id: eventId }, dataType: 'json'
      });
    }
  });

  // ── Save ──────────────────────────────────────────────────────────────────

  function saveEvent() {
    var d = eventData();
    if (!d.title && !d.location && !d.screentime) return;

    setEventStatus('saving...');

    if (eventId === null) {
      $.ajax({
        type: 'POST', url: '/dashboard/event.php?action=create',
        data: d, dataType: 'json',
        success: function(res) {
          if (res.success) {
            eventId = res.event_id;
            eventAutosaveOn = true;
            enableEventPosterInput();
            $('#event-publish').prop('disabled', false);
            $('#event-save').text('Save Draft');
            setEventStatus('draft saved');
          } else {
            setEventStatus('save failed', '#b22222');
            $('#event-save').prop('disabled', false).text('Save Draft');
          }
        },
        error: function() {
          setEventStatus('save failed', '#b22222');
          $('#event-save').prop('disabled', false).text('Save Draft');
        }
      });
    } else {
      d.event_id = eventId;
      $.ajax({
        type: 'POST', url: '/dashboard/event.php?action=update',
        data: d, dataType: 'json',
        success: function(res) {
          setEventStatus(res.success ? 'saved' : 'save failed', res.success ? '' : '#b22222');
        },
        error: function() { setEventStatus('save failed', '#b22222'); }
      });
    }
  }

  // autosave only fires after the first manual save
  $('#event-title, #event-location, #event-address, #event-screentime').on('input change', function() {
    if (!eventAutosaveOn) return;
    setEventStatus('unsaved', '#888');
    clearTimeout(eventSaveTimer);
    eventSaveTimer = setTimeout(saveEvent, IDLE_MS);
  });

  $('#event-save').on('click', function() {
    $(this).prop('disabled', true).text('Saving...');
    saveEvent();
  });

  // ── Event row state helpers ──────────────────────────────────────────────

  function setEventRowLive($row) {
    var id = $row.data('id');
    $row.attr('data-active', '1').addClass('post-live');
    $row.find('.post-status').attr('class', 'post-status status-live').text('live');
    $row.find('.post-row-actions').html(
      '<a class="post-view" href="/events/?id=' + id + '" target="_blank">view</a>' +
      '<button class="event-edit" data-id="' + id + '">edit</button>' +
      '<button class="event-unpublish" data-id="' + id + '">unpublish</button>'
    );
  }

  function setEventRowDraft($row) {
    $row.attr('data-active', '0').removeClass('post-live');
    $row.find('.post-status').attr('class', 'post-status status-draft').text('draft');
    $row.find('.post-row-actions').html(
      '<button class="event-delete" data-id="' + $row.data('id') + '">&#x2715;</button>'
    );
  }

  // ── Load event into editor (draft row click or edit button) ────────────────

  function loadEvent(id) {
    $.ajax({
      type: 'GET', url: '/dashboard/event.php?action=get&event_id=' + id,
      dataType: 'json',
      success: function(res) {
        if (!res.success) return;
        var ev = res.event;

        $('#event-title').val(ev.title);
        $('#event-location').val(ev.location);
        $('#event-address').val(ev.address || '');
        $('#event-screentime').val(ev.screentime_local);

        eventId         = parseInt(id);
        eventAutosaveOn = true;
        clearTimeout(eventSaveTimer);

        enableEventPosterInput();
        if (ev.poster) {
          showEventPosterPreview('/uploads/events/' + ev.poster);
        } else {
          clearEventPosterPreview();
        }

        $('#event-save').prop('disabled', false).text('Save Draft');

        if (parseInt(ev.active) === 1) {
          $('#event-save').text('Save');
          $('#event-publish').prop('disabled', true).text('Published');
          setEventStatus('editing live screening', '#5a9e6f');
        } else {
          $('#event-save').text('Save Draft');
          $('#event-publish').prop('disabled', false).text('Publish');
          setEventStatus('draft loaded');
        }

        activateTab('events');
      }
    });
  }

  $(document).on('click', '.event-edit', function(e) {
    e.stopPropagation();
    loadEvent($(this).data('id'));
  });

  $(document).on('click', '.event-row', function(e) {
    if ($(e.target).closest('.event-delete, .event-unpublish, .event-edit, .draft-confirm').length) return;
    if ($(this).attr('data-active') === '1') return;
    loadEvent($(this).data('id'));
  });

  // ── Delete draft (with inline confirmation) ─────────────────────────────────

  $(document).on('click', '.event-delete', function(e) {
    e.stopPropagation();
    var $btn = $(this);
    var $row = $btn.closest('.event-row');

    if ($row.find('.draft-confirm').length) return;

    $btn.hide();
    $row.find('.post-row-actions').append(
      '<span class="draft-confirm">delete? ' +
      '<span class="draft-confirm-yes">yes</span> / ' +
      '<span class="draft-confirm-no">no</span></span>'
    );
  });

  $(document).on('click', '.event-row .draft-confirm-no', function(e) {
    e.stopPropagation();
    var $row = $(this).closest('.event-row');
    $row.find('.draft-confirm').remove();
    $row.find('.event-delete').show();
  });

  $(document).on('click', '.event-row .draft-confirm-yes', function(e) {
    e.stopPropagation();
    var $row = $(this).closest('.event-row');
    var id   = $row.data('id');

    $.ajax({
      type: 'POST', url: '/dashboard/event.php?action=delete',
      data: { event_id: id }, dataType: 'json',
      success: function(res) {
        if (!res.success) return;
        $row.fadeOut(200, function() {
          $(this).remove();
          if ($('.event-row').length === 0) {
            $('#events-list').replaceWith('<p class="drafts-empty">No screenings submitted yet.</p>');
          }
        });
        if (eventId === id) {
          eventId = null; eventAutosaveOn = false; clearTimeout(eventSaveTimer);
          $('#event-title, #event-location, #event-address, #event-screentime').val('');
          $('#event-publish').prop('disabled', true).text('Publish');
          $('#event-save').prop('disabled', false).text('Save Draft');
          clearEventPosterPreview();
          $('#event-poster').prop('disabled', true);
          $('#event-poster-label').removeClass('enabled');
          $('#event-poster-hint').text('Save a draft first');
          setEventStatus('');
        }
      }
    });
  });

  // ── Unpublish ────────────────────────────────────────────────────────────

  $(document).on('click', '.event-unpublish', function(e) {
    e.stopPropagation();
    var $row = $(this).closest('.event-row');
    var id   = parseInt($row.data('id'));
    $.ajax({
      type: 'POST', url: '/dashboard/event.php?action=unpublish',
      data: { event_id: id }, dataType: 'json',
      success: function(res) {
        if (res.success) setEventRowDraft($row);
      }
    });
  });

  // ── Publish ──────────────────────────────────────────────────────────────

  $('#event-publish').on('click', function() {
    if (!eventId) return;
    var eid = eventId;
    $.ajax({
      type: 'POST', url: '/dashboard/event.php?action=publish',
      data: { event_id: eid }, dataType: 'json',
      success: function(res) {
        if (res.success) {
          setEventStatus('published', '#5a9e6f');
          $('#event-publish').prop('disabled', true).text('Published');
          $('#event-save').prop('disabled', true);
          eventAutosaveOn = false;
          var $row = $('.event-row[data-id="' + eid + '"]');
          if ($row.length) setEventRowLive($row);
        }
      }
    });
  });

});
