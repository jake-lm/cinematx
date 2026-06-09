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
  activateTab(['write', 'posts', 'account'].indexOf(initTab) !== -1 ? initTab : 'write');

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
      title:      $('#post-title').val().trim(),
      subtitle:   $('#post-subtitle').val().trim(),
      content:    $('#post-content').val().trim(),
      type:       $('#post-type').val(),
      photo_cred: $('#post-photo-cred').val().trim()
    };
  }

  function setStatus(msg, color) {
    $('#autosave-status').text(msg).css('color', color || '');
  }

  // ── Image upload ───────────────────────────────────────────────────────────

  function enableImageInput() {
    $('#post-image').prop('disabled', false);
    $('#post-image-label').addClass('enabled');
    $('#post-image-hint').text('');
  }

  function showImagePreview(src) {
    $('#post-image-thumb').attr('src', src);
    $('#post-image-preview').show();
  }

  function clearImagePreview() {
    $('#post-image-thumb').attr('src', '');
    $('#post-image-preview').hide();
    $('#post-image').val('');
  }

  function uploadImage(file) {
    if (!postId || !file) return;
    var fd = new FormData();
    fd.append('post_id', postId);
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
          setStatus('image upload failed', '#b22222');
          clearImagePreview();
        }
      },
      error: function() {
        setStatus('image upload failed', '#b22222');
        clearImagePreview();
      }
    });
  }

  $('#post-image').on('change', function() {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) { showImagePreview(e.target.result); };
    reader.readAsDataURL(file);
    uploadImage(file);
  });

  $('#post-image-remove').on('click', function() {
    clearImagePreview();
    // clear image in DB via update with empty image flag
    if (postId) {
      $.ajax({
        type: 'POST', url: '/dashboard/post.php?action=upload_image&remove=1',
        data: { post_id: postId }, dataType: 'json'
      });
    }
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
            enableImageInput();
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
        $('#post-photo-cred').val(res.post.photo_cred || '');

        postId     = parseInt(id);
        autosaveOn = true;
        clearTimeout(saveTimer);

        enableImageInput();
        if (res.post.image) {
          showImagePreview('/uploads/posts/' + res.post.image);
        } else {
          clearImagePreview();
        }

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
    e.stopPropagation();
    cancelConfirm($(this).closest('.draft-row'));
  });

  $(document).on('click', '.draft-confirm-yes', function(e) {
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
          $('#post-title, #post-subtitle, #post-content, #post-photo-cred').val('');
          $('#post-type').val('');
          $('#post-publish').prop('disabled', true).text('Publish');
          $('#post-save').prop('disabled', false).text('Save Draft');
          clearImagePreview();
          $('#post-image').prop('disabled', true);
          $('#post-image-label').removeClass('enabled');
          $('#post-image-hint').text('Save a draft first');
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

});
