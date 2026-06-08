(function ($) {
  'use strict';

  var config = window.KOOPO_TICKETS_AUTHOR || {};

  function syncNativeAuthorField(vendor) {
    if (!vendor || !vendor.id) return;

    var $select = $('#post_author_override, #post_author').first();
    if (!$select.length) return;

    var id = String(vendor.id);
    if (!$select.find('option[value="' + id + '"]').length) {
      $select.append($('<option>', {
        value: id,
        text: vendor.label || vendor.value || ('Vendor #' + id)
      }));
    }

    $select.val(id).trigger('change');
  }

  function renderStatus(item) {
    var $status = $('#koopo-ticket-event-author-status');
    if (!$status.length || !item) return;

    var text = item.disabled && item.reason ? item.reason : (item.meta || '');
    $status.text(text).toggleClass('koopo-ticket-author-error', !!item.disabled);
  }

  $(function () {
    var $input = $('#koopo-ticket-event-author-search');
    var $hidden = $('#koopo-ticket-event-author-id');
    if (!$input.length || !$hidden.length || !config.ajaxUrl) return;

    $input.autocomplete({
      minLength: parseInt(config.minChars || 2, 10),
      delay: 250,
      source: function (request, response) {
        $.ajax({
          url: config.ajaxUrl,
          method: 'GET',
          dataType: 'json',
          data: {
            action: 'koopo_tickets_search_event_authors',
            nonce: config.nonce,
            event_id: $input.data('event-id'),
            term: request.term
          }
        }).done(function (items) {
          response($.isArray(items) ? items : []);
        }).fail(function () {
          response([]);
        });
      },
      focus: function (event, ui) {
        event.preventDefault();
        if (ui.item && ui.item.value) {
          $input.val(ui.item.value);
          renderStatus(ui.item);
        }
      },
      select: function (event, ui) {
        event.preventDefault();
        if (!ui.item || ui.item.disabled) {
          renderStatus(ui.item || null);
          return false;
        }

        $input.val(ui.item.label || ui.item.value || '');
        $hidden.val(ui.item.id || '');
        renderStatus(ui.item);
        syncNativeAuthorField(ui.item);
        return false;
      },
      change: function () {
        if (!$input.val()) {
          $hidden.val('');
        }
      }
    }).autocomplete('instance')._renderItem = function (ul, item) {
      var $row = $('<div class="koopo-ticket-author-result">');
      $('<strong>').text(item.label || item.value || '').appendTo($row);
      if (item.meta) {
        $('<span class="koopo-ticket-author-result__meta">').text(item.meta).appendTo($row);
      }
      if (item.disabled && item.reason) {
        $('<span class="koopo-ticket-author-result__error">').text(item.reason).appendTo($row);
      }

      return $('<li>')
        .toggleClass('koopo-ticket-author-result-disabled', !!item.disabled)
        .append($row)
        .appendTo(ul);
    };

    $('#post').on('submit', function () {
      var id = parseInt($hidden.val(), 10) || 0;
      if (id > 0) {
        syncNativeAuthorField({
          id: id,
          label: $input.val()
        });
      }
    });
  });
})(jQuery);
