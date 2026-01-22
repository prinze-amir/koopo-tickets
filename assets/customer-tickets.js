(function ($) {
  'use strict';

  var api = window.KOOPO_TICKETS_DASH || {};
  if (!api.api_url || !api.nonce) return;

  function request(path, method, data) {
    return $.ajax({
      url: api.api_url.replace(/\/$/, '') + '/' + path.replace(/^\//, ''),
      method: method || 'GET',
      data: data ? JSON.stringify(data) : null,
      contentType: 'application/json',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', api.nonce);
      }
    });
  }

  function renderTickets(items) {
    var $root = $('#koopo-ticket-dashboard');
    if (!items.length) {
      $root.html('<p>' + (api.i18n && api.i18n.no_tickets ? api.i18n.no_tickets : 'No tickets found.') + '</p>');
      return;
    }

    var html = items.map(function (item) {
      var guestsHtml = '';
      if (item.quantity > 1) {
        guestsHtml = '<button class="button koopo-ticket-toggle-guests">Show Guests</button>' +
          '<div class="koopo-ticket-guest-grid" data-guest-grid style="display:none;">' +
            buildGuestInputs(item) +
          '</div>';
      }

      var image = item.event_image ? '<div class="koopo-ticket-thumb" style="background-image:url(' + item.event_image + ')"></div>' : '';
      var title = item.event_url ? '<a class="koopo-ticket-event-link" href="' + item.event_url + '">' + (item.event_title || '') + '</a>' : (item.event_title || '');
      var attendees = buildAttendeeAvatars(item.attendees || []);

      return '<div class="koopo-ticket-card" data-item-id="' + item.item_id + '">' +
        '<div class="koopo-ticket-card__header">' +
          image +
          '<div class="koopo-ticket-card__info">' +
            '<div class="koopo-ticket-event-title">' + title + '</div>' +
            '<div class="koopo-ticket-meta">' + item.ticket_name + '</div>' +
            (item.event_location ? '<div class="koopo-ticket-meta">' + item.event_location + '</div>' : '') +
            '<div class="koopo-ticket-meta">Tickets: ' + item.quantity + '</div>' +
          '</div>' +
        '</div>' +
        '<div class="koopo-ticket-meta koopo-ticket-dates">' +
          (item.schedule_date ? '<span>Date: ' + item.schedule_date + '</span>' : '') +
          (item.schedule_time ? '<span>Time: ' + item.schedule_time + '</span>' : '') +
        '</div>' +
        attendees +
        '<div class="koopo-ticket-meta"><span class="koopo-ticket-status">' + (item.status_label || item.status) + '</span></div>' +
        guestsHtml +
        '<div class="koopo-ticket-actions">' +
          (item.quantity > 1 ? '<button class="button koopo-ticket-save">Save Guests</button>' : '') +
          '<button class="button koopo-ticket-send">Send Tickets</button>' +
          '<a class="button" target="_blank" href="?koopo_ticket_print=' + item.item_id + '">View/Print Tickets</a>' +
          '<a class="button" target="_blank" href="?koopo_ticket_print=' + item.item_id + '&download=1">Download</a>' +
        '</div>' +
        '<div class="koopo-ticket-notice"></div>' +
      '</div>';
    }).join('');

    $root.html(html);
  }

  function buildGuestInputs(item) {
    var guests = Array.isArray(item.guests) ? item.guests : [];
    var attendees = Array.isArray(item.attendees) ? item.attendees : [];
    var html = '';

    for (var i = 0; i < item.quantity - 1; i += 1) {
      var guest = guests[i] || {};
      var attendee = attendees[i + 1] || {};
      var assigned = (attendee.email || attendee.name);
      html += '<div class="koopo-ticket-guest-card" data-guest-index="' + i + '">' +
        '<h4>Guest ' + (i + 1) + '</h4>' +
        buildAssignedGuest(attendee, assigned) +
        buildGuestEditor(guest, assigned) +
      '</div>';
    }

    return html;
  }

  function buildAssignedGuest(attendee, assigned) {
    if (!assigned) return '';
    var avatar = attendee.avatar ? '<span class="koopo-ticket-assigned-avatar" style="background-image:url(' + attendee.avatar + ')"></span>' : '';
    var name = attendee.name || attendee.label || 'Assigned';
    var email = attendee.email ? '<div class="koopo-ticket-meta">' + attendee.email + '</div>' : '';
    return '<div class="koopo-ticket-assigned">' +
      avatar +
      '<div><div class="koopo-ticket-assigned-name">' + name + '</div>' + email + '</div>' +
      '<button type="button" class="button koopo-ticket-unassign">Remove</button>' +
    '</div>';
  }

  function buildGuestEditor(guest, assigned) {
    var style = assigned ? 'style="display:none;"' : '';
    return '<div class="koopo-ticket-guest-editor" ' + style + '>' +
      '<label>Assign from friends</label>' +
      '<input type="text" class="koopo-ticket-friend-search" placeholder="Search friends..." data-guest-search>' +
      '<div class="koopo-ticket-friend-spinner"></div>' +
      '<div class="koopo-ticket-friend-results" data-guest-results style="display:none;"></div>' +
      '<label>Name</label><input type="text" data-guest-name value="' + (guest.name || '') + '">' +
      '<label>Email</label><input type="email" data-guest-email value="' + (guest.email || '') + '">' +
      '<label>Phone</label><input type="tel" data-guest-phone value="' + (guest.phone || '') + '">' +
    '</div>';
  }

  function buildAttendeeAvatars(attendees) {
    if (!Array.isArray(attendees) || !attendees.length) return '';
    var avatars = attendees.map(function (attendee) {
      if (!attendee.avatar) return '';
      return '<div class="koopo-ticket-avatar" title="' + attendee.label + '" style="background-image:url(' + attendee.avatar + ')"></div>';
    }).join('');

    if (!avatars) return '';
    return '<div class="koopo-ticket-avatars">' + avatars + '</div>';
  }

  function collectGuests($card) {
    var guests = [];
    $card.find('.koopo-ticket-guest-card').each(function () {
      var $guest = $(this);
      guests.push({
        name: $guest.find('[data-guest-name]').val(),
        email: $guest.find('[data-guest-email]').val(),
        phone: $guest.find('[data-guest-phone]').val()
      });
    });
    return guests;
  }

  function showNotice($card, type, message) {
    var $notice = $card.find('.koopo-ticket-notice');
    $notice.removeClass('is-success is-error');
    $notice.addClass(type === 'success' ? 'is-success' : 'is-error');
    $notice.text(message).show();
  }

  function loadTickets() {
    var $root = $('#koopo-ticket-dashboard');
    $root.html('<p>' + (api.i18n && api.i18n.loading ? api.i18n.loading : 'Loading...') + '</p>');

    request('customer/tickets', 'GET').done(function (items) {
      renderTickets(items || []);
    });
  }

  $(document).on('click', '.koopo-ticket-save', function () {
    var $card = $(this).closest('.koopo-ticket-card');
    var itemId = $card.data('item-id');
    var guests = collectGuests($card);
    var $btn = $(this);
    $btn.prop('disabled', true).addClass('is-loading');

    request('customer/tickets/' + itemId + '/guests', 'POST', { guests: guests }).done(function () {
      showNotice($card, 'success', api.i18n && api.i18n.save_success ? api.i18n.save_success : 'Saved.');
    }).fail(function () {
      showNotice($card, 'error', api.i18n && api.i18n.save_error ? api.i18n.save_error : 'Error.');
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '.koopo-ticket-send', function () {
    var $card = $(this).closest('.koopo-ticket-card');
    var itemId = $card.data('item-id');
    var $btn = $(this);
    $btn.prop('disabled', true).addClass('is-loading');

    request('customer/tickets/' + itemId + '/send', 'POST').done(function () {
      showNotice($card, 'success', api.i18n && api.i18n.send_success ? api.i18n.send_success : 'Sent.');
    }).fail(function () {
      showNotice($card, 'error', api.i18n && api.i18n.send_error ? api.i18n.send_error : 'Error.');
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '.koopo-ticket-toggle-guests', function () {
    var $btn = $(this);
    var $grid = $btn.closest('.koopo-ticket-card').find('[data-guest-grid]');
    $grid.toggle();
    $btn.text($grid.is(':visible') ? 'Hide Guests' : 'Show Guests');
  });

  $(document).on('click', '.koopo-ticket-unassign', function () {
    var $card = $(this).closest('.koopo-ticket-guest-card');
    $card.find('.koopo-ticket-assigned').remove();
    $card.find('.koopo-ticket-guest-editor').show();
    $card.find('[data-guest-name], [data-guest-email], [data-guest-phone]').val('');
  });

  $(document).on('input', '.koopo-ticket-friend-search', function () {
    var $input = $(this);
    var query = $input.val();
    var $results = $input.closest('.koopo-ticket-guest-card').find('[data-guest-results]');
    var $spinner = $input.closest('.koopo-ticket-guest-card').find('.koopo-ticket-friend-spinner');
    if (!query || query.length < 2) {
      $results.hide().empty();
      $spinner.hide();
      return;
    }

    $spinner.show();
    request('customer/friends?search=' + encodeURIComponent(query), 'GET').done(function (items) {
      if (!items || !items.length) {
        $results.hide().empty();
        $spinner.hide();
        return;
      }

      var html = items.map(function (friend) {
        return '<div class="koopo-ticket-friend" data-name="' + friend.name + '" data-email="' + friend.email + '">' +
          '<span class="koopo-ticket-friend-avatar" style="background-image:url(' + friend.avatar + ')"></span>' +
          '<span>' + friend.name + ' (' + friend.email + ')</span>' +
        '</div>';
      }).join('');

      $results.html(html).show();
      $spinner.hide();
    });
  });

  $(document).on('click', '.koopo-ticket-friend', function () {
    var $friend = $(this);
    var $card = $friend.closest('.koopo-ticket-guest-card');
    $card.find('[data-guest-name]').val($friend.data('name'));
    $card.find('[data-guest-email]').val($friend.data('email'));
    $card.find('[data-guest-results]').hide().empty();
    $card.find('.koopo-ticket-friend-spinner').hide();
    $card.find('.koopo-ticket-guest-editor').hide();
    $card.find('.koopo-ticket-assigned').remove();
    var avatar = $friend.find('.koopo-ticket-friend-avatar').css('background-image');
    var assigned = '<div class=\"koopo-ticket-assigned\">' +
      '<span class=\"koopo-ticket-assigned-avatar\" style=\"background-image:' + avatar + '\"></span>' +
      '<div><div class=\"koopo-ticket-assigned-name\">' + $friend.data('name') + '</div><div class=\"koopo-ticket-meta\">' + $friend.data('email') + '</div></div>' +
      '<button type=\"button\" class=\"button koopo-ticket-unassign\">Remove</button>' +
    '</div>';
    $card.prepend(assigned);
  });

  $(function () {
    loadTickets();
  });
})(jQuery);
