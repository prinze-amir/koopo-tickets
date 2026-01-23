(function ($) {
  'use strict';

  var api = window.KOOPO_TICKETS_VENDOR || {};
  if (!api.rest || !api.nonce) return;

  function request(path, method, data) {
    var base = api.rest.replace(/\/$/, '');
    var url = base + '/' + path.replace(/^\//, '');
    return $.ajax({
      url: url,
      method: method || 'POST',
      data: data ? JSON.stringify(data) : null,
      contentType: 'application/json',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', api.nonce);
      }
    });
  }

  function renderResult(data, isError) {
    var $out = $('#koopo-ticket-checkin-result');
    if (!$out.length) return;
    if (isError) {
      $out.text(data || 'Unable to verify ticket.').css('color', '#7a1e1e');
      return;
    }
    var parts = [];
    parts.push('Status: ' + data.status);
    parts.push('Attendee: ' + (data.attendee_name || '—'));
    if (data.event_title) {
      parts.push('Event: ' + data.event_title);
    } else if (data.event_id) {
      parts.push('Event ID: ' + data.event_id);
    }
    if (data.schedule_date || data.schedule_time) {
      parts.push('Date/Time: ' + [data.schedule_date, data.schedule_time].filter(Boolean).join(' '));
    }
    if (data.seat) {
      parts.push('Seat: ' + data.seat);
    }
    var text = parts.join(' | ');
    $out.text(text).css('color', '#222');
  }

  $(document).on('submit', '#koopo-ticket-checkin', function (e) {
    e.preventDefault();
    var code = $('#koopo-ticket-code').val();
    if (!code) return;
    var $btn = $(this).find('button[type=\"submit\"]');
    $btn.prop('disabled', true).addClass('is-loading');
    request('tickets/verify', 'POST', { code: code }).done(function (data) {
      renderResult(data, false);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Ticket not found.';
      renderResult(msg, true);
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '#koopo-ticket-redeem', function () {
    var code = $('#koopo-ticket-code').val();
    if (!code) return;
    var $btn = $(this);
    $btn.prop('disabled', true).addClass('is-loading');
    request('tickets/redeem', 'POST', { code: code }).done(function (data) {
      renderResult(data, false);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Unable to redeem.';
      renderResult(msg, true);
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });
})(jQuery);
