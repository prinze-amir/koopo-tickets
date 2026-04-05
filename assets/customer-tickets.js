(function ($) {
  'use strict';

  var api = window.KOOPO_TICKETS_DASH || {};
  if (!api.api_url || !api.nonce) return;

  var currentPage = 1;
  var totalPages = 1;
  var totalItems = 0;
  var perPage = parseInt(api.per_page || 10, 10) || 10;

  function text(key, fallback) {
    return (api.i18n && api.i18n[key]) ? api.i18n[key] : fallback;
  }

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

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      })[char];
    });
  }

  function escapeAttr(value) {
    return escapeHtml(value);
  }

  function safeUrl(value) {
    var url = String(value || '').trim();
    if (!url) return '';
    if (/^https?:\/\//i.test(url) || /^[/?#]/.test(url) || url.indexOf('//') === 0) {
      return url;
    }
    return '';
  }

  function backgroundStyle(value) {
    var url = safeUrl(value);
    if (!url) return '';
    return ' style="background-image:url(' + escapeAttr(encodeURI(url)) + ')"';
  }

  function renderPagination() {
    var $pagination = $('#koopo-ticket-pagination');
    if (!$pagination.length) return;

    if (totalPages <= 1) {
      $pagination.hide().empty();
      return;
    }

    var pages = [];
    var start = Math.max(1, currentPage - 2);
    var end = Math.min(totalPages, currentPage + 2);

    for (var page = start; page <= end; page += 1) {
      pages.push(
        '<button type="button" class="button koopo-ticket-page' + (page === currentPage ? ' is-current' : '') + '" data-page="' + page + '">' +
          page +
        '</button>'
      );
    }

    var label = text('pagination_label', 'Page %1$s of %2$s')
      .replace('%1$s', currentPage)
      .replace('%2$s', totalPages);

    $pagination.html(
      '<button type="button" class="button koopo-ticket-page-nav" data-page="' + (currentPage - 1) + '"' + (currentPage <= 1 ? ' disabled' : '') + '>' +
        escapeHtml(text('pagination_prev', 'Previous')) +
      '</button>' +
      '<div class="koopo-ticket-pagination__pages">' + pages.join('') + '</div>' +
      '<span class="koopo-ticket-pagination__label">' + escapeHtml(label) + '</span>' +
      '<button type="button" class="button koopo-ticket-page-nav" data-page="' + (currentPage + 1) + '"' + (currentPage >= totalPages ? ' disabled' : '') + '>' +
        escapeHtml(text('pagination_next', 'Next')) +
      '</button>'
    ).show();
  }

  function renderTickets(items) {
    var $root = $('#koopo-ticket-dashboard');
    if (!items.length) {
      $root.html('<p>' + escapeHtml(text('no_tickets', 'No tickets found.')) + '</p>');
      renderPagination();
      return;
    }

    var html = items.map(function (item) {
      var canManageGuests = item.can_manage_guests !== false;
      var guestsHtml = '';
      if (canManageGuests && item.quantity > 1) {
        guestsHtml = '<button class="button koopo-ticket-toggle-guests">Show Guests</button>' +
          '<div class="koopo-ticket-guest-grid" data-guest-grid style="display:none;">' +
            buildGuestInputs(item) +
          '</div>';
      }

      var image = item.event_image ? '<div class="koopo-ticket-thumb"' + backgroundStyle(item.event_image) + '></div>' : '';
      var eventTitle = escapeHtml(item.event_title || '');
      var eventUrl = safeUrl(item.event_url);
      var title = eventUrl
        ? '<a class="koopo-ticket-event-link" href="' + escapeAttr(eventUrl) + '">' + eventTitle + '</a>'
        : eventTitle;
      var attendees = buildAttendeeAvatars(item.attendees || []);
      var ownershipBadge = item.ownership === 'recipient'
        ? '<div class="koopo-ticket-meta"><span class="koopo-ticket-transfer-badge is-owned">Transferred to you</span></div>'
        : '';
      var viewUrl = safeUrl(item.view_url) || '';
      var printUrl = safeUrl(item.print_url) || '';
      var downloadUrl = safeUrl(item.download_url) || '';

      var dateHtml = '';
      if (item.schedule_date || item.schedule_time) {
        dateHtml = '<div class="koopo-ticket-meta koopo-ticket-dates">' +
          (item.schedule_date ? '<span>Date: ' + escapeHtml(item.schedule_date) + '</span>' : '') +
          (item.schedule_time ? '<span>Time: ' + escapeHtml(item.schedule_time) + '</span>' : '') +
        '</div>';
      } else if (item.schedule_label) {
        dateHtml = '<div class="koopo-ticket-meta koopo-ticket-dates"><span>Date: ' + escapeHtml(item.schedule_label) + '</span></div>';
      }

      return '<div class="koopo-ticket-card" data-item-id="' + escapeAttr(item.item_id || 0) + '">' +
        '<div class="koopo-ticket-card__header">' +
          image +
          '<div class="koopo-ticket-card__info">' +
            '<div class="koopo-ticket-event-title">' + title + '</div>' +
            '<div class="koopo-ticket-meta">' + escapeHtml(item.ticket_name || '') + '</div>' +
            (item.event_location ? '<div class="koopo-ticket-meta">' + escapeHtml(item.event_location) + '</div>' : '') +
            '<div class="koopo-ticket-meta">Tickets: ' + escapeHtml(item.quantity) + '</div>' +
            ownershipBadge +
          '</div>' +
        '</div>' +
        dateHtml +
        attendees +
        '<div class="koopo-ticket-meta"><span class="koopo-ticket-status">' + escapeHtml(item.status_label || item.status || '') + '</span></div>' +
        guestsHtml +
        '<div class="koopo-ticket-actions">' +
          (canManageGuests && item.quantity > 1 ? '<button class="button koopo-ticket-save">Save Guests</button>' : '') +
          (canManageGuests && item.quantity > 1 ? '<button class="button koopo-ticket-send-toggle">Manage Guest Tickets</button>' : '') +
          (viewUrl ? '<a class="button" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(viewUrl) + '">View</a>' : '') +
          (printUrl ? '<a class="button" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(printUrl) + '">Print</a>' : '') +
          (downloadUrl ? '<a class="button" target="_blank" rel="noopener noreferrer" href="' + escapeAttr(downloadUrl) + '">Download</a>' : '') +
        '</div>' +
        '<div class="koopo-ticket-notice"></div>' +
      '</div>';
    }).join('');

    $root.html(html);
    renderPagination();
  }

  function buildGuestInputs(item) {
    var guests = Array.isArray(item.guests) ? item.guests : [];
    var guestBySlot = {};
    guests.forEach(function (guest, index) {
      var key = parseInt(guest && guest.slot_index, 10);
      if (isNaN(key) || key < 0) {
        key = index;
      }
      guestBySlot[key] = guest || {};
    });
    var attendees = Array.isArray(item.attendees) ? item.attendees : [];
    var guestAttendees = attendees.filter(function (_attendee, index) {
      return index > 0;
    });
    var html = '';

    guestAttendees.forEach(function (attendee, index) {
      var slotIndex = parseInt(attendee.guest_slot_index, 10);
      if (isNaN(slotIndex) || slotIndex < 0) {
        slotIndex = index;
      }
      var guest = guestBySlot[slotIndex] || {};
      var assigned = !!(attendee.user_id || guest.user_id || attendee.name || attendee.email);
      html += '<div class="koopo-ticket-guest-card" data-guest-slot-index="' + slotIndex + '">' +
        '<h4>Guest ' + (slotIndex + 1) + '</h4>' +
        buildAssignedGuest(attendee, assigned) +
        buildGuestEditor(guest, attendee, assigned) +
        buildGuestSendActions(attendee, assigned) +
      '</div>';
    });

    return html;
  }

  function buildAssignedGuest(attendee, assigned) {
    var transfer = attendee.transfer || null;
    if (!assigned && !transfer) return '';

    var name = attendee.name || attendee.label || 'Assigned';
    var email = attendee.email || '';
    var avatar = attendee.avatar ? '<span class="koopo-ticket-assigned-avatar"' + backgroundStyle(attendee.avatar) + '></span>' : '';
    var statusLabel = 'Assigned to';
    var extra = '';
    var canRemove = !!assigned && !transfer && attendee.ticket_status !== 'transferred';

    if (transfer && transfer.status === 'pending') {
      statusLabel = 'Pending transfer';
      name = transfer.recipient_name || name || 'Pending recipient';
      email = transfer.recipient_email || email;
      extra = '<div class="koopo-ticket-meta">Awaiting acceptance</div>';
      canRemove = false;
    } else if (attendee.ticket_status === 'transferred') {
      statusLabel = 'Transferred to';
      canRemove = false;
    }

    return '<div class="koopo-ticket-assigned">' +
      avatar +
      '<div>' +
        '<div class="koopo-ticket-assigned-label">' + escapeHtml(statusLabel) + '</div>' +
        '<div class="koopo-ticket-assigned-name">' + escapeHtml(name) + '</div>' +
        (email ? '<div class="koopo-ticket-meta">' + escapeHtml(email) + '</div>' : '') +
        extra +
      '</div>' +
      (canRemove ? '<button type="button" class="button koopo-ticket-unassign">Remove</button>' : '') +
    '</div>';
  }

  function buildGuestEditor(guest, attendee, assigned) {
    var transfer = attendee.transfer || null;
    var locked = !!transfer || attendee.ticket_status === 'transferred';
    var style = (assigned || locked) ? 'style="display:none;"' : '';
    var userId = guest.user_id || attendee.user_id || 0;
    var name = guest.name || attendee.name || '';
    var email = guest.email || attendee.email || '';
    var phone = guest.phone || attendee.phone || '';
    return '<div class="koopo-ticket-guest-editor" ' + style + '>' +
      '<label>Assign to user</label>' +
      '<input type="text" class="koopo-ticket-friend-search" placeholder="Search friends..." data-guest-search>' +
      '<div class="koopo-ticket-friend-spinner"></div>' +
      '<div class="koopo-ticket-friend-results" data-guest-results style="display:none;"></div>' +
      '<input type="hidden" data-guest-user-id value="' + escapeAttr(userId) + '">' +
      '<label>Name</label><input type="text" data-guest-name value="' + escapeAttr(name) + '">' +
      '<label>Email</label><input type="email" data-guest-email value="' + escapeAttr(email) + '">' +
      '<label>Phone</label><input type="tel" data-guest-phone value="' + escapeAttr(phone) + '">' +
    '</div>';
  }

  function buildGuestSendActions(attendee, assigned) {
    var ticketId = attendee.ticket_id || 0;
    if (!ticketId) return '';

    var transfer = attendee.transfer || null;
    if (attendee.ticket_status === 'transferred') {
      return '<div class="koopo-ticket-guest-actions">' +
        '<span class="koopo-ticket-transfer-badge is-accepted">Accepted transfer</span>' +
      '</div>';
    }

    if (transfer && transfer.status === 'pending') {
      return '<div class="koopo-ticket-guest-actions">' +
        '<span class="koopo-ticket-transfer-badge is-pending">Pending acceptance</span>' +
        '<button class="button koopo-ticket-transfer-cancel" data-ticket-id="' + escapeAttr(ticketId) + '">Cancel Transfer</button>' +
      '</div>';
    }

    return '<div class="koopo-ticket-guest-actions">' +
      '<button class="button koopo-ticket-guest-send" data-ticket-id="' + escapeAttr(ticketId) + '">' +
        (assigned ? 'Send Ticket Invite' : 'Send Ticket Invite') +
      '</button>' +
    '</div>';
  }

  function buildAttendeeAvatars(attendees) {
    if (!Array.isArray(attendees) || !attendees.length) return '';
    var avatars = attendees.map(function (attendee) {
      if (!attendee.avatar) return '';
      return '<div class="koopo-ticket-avatar" title="' + escapeAttr(attendee.label || '') + '"' + backgroundStyle(attendee.avatar) + '></div>';
    }).join('');

    if (!avatars) return '';
    return '<div class="koopo-ticket-avatars">' + avatars + '</div>';
  }

  function collectGuests($card) {
    var guests = [];
    $card.find('.koopo-ticket-guest-card').each(function () {
      var $guest = $(this);
      guests.push({
        slot_index: parseInt($guest.data('guest-slot-index'), 10) || 0,
        user_id: parseInt($guest.find('[data-guest-user-id]').val(), 10) || 0,
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

  function loadTickets(page) {
    currentPage = page || 1;
    var $root = $('#koopo-ticket-dashboard');
    $root.html('<p>' + escapeHtml(text('loading', 'Loading...')) + '</p>');

    request('customer/tickets?page=' + encodeURIComponent(currentPage) + '&per_page=' + encodeURIComponent(perPage), 'GET')
      .done(function (items, _textStatus, xhr) {
        currentPage = parseInt(xhr.getResponseHeader('X-WP-Page'), 10) || currentPage;
        totalPages = parseInt(xhr.getResponseHeader('X-WP-TotalPages'), 10) || 1;
        totalItems = parseInt(xhr.getResponseHeader('X-WP-Total'), 10) || 0;
        renderTickets(items || []);
      })
      .fail(function () {
        totalPages = 1;
        totalItems = 0;
        $root.html('<p>' + escapeHtml(text('send_error', 'Unable to load tickets.')) + '</p>');
        renderPagination();
      });
  }

  $(document).on('click', '.koopo-ticket-save', function () {
    var $card = $(this).closest('.koopo-ticket-card');
    var itemId = $card.data('item-id');
    var guests = collectGuests($card);
    var $btn = $(this);
    $btn.prop('disabled', true).addClass('is-loading');

    request('customer/tickets/' + itemId + '/guests', 'POST', { guests: guests }).done(function () {
      showNotice($card, 'success', text('save_success', 'Saved.'));
      loadTickets(currentPage);
    }).fail(function () {
      showNotice($card, 'error', text('save_error', 'Error.'));
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '.koopo-ticket-send-toggle', function () {
    var $btn = $(this);
    var $card = $btn.closest('.koopo-ticket-card');
    var $grid = $card.find('[data-guest-grid]');
    if (!$grid.length) return;
    $grid.toggle();
    $btn.text($grid.is(':visible') ? 'Hide Guest Tickets' : 'Manage Guest Tickets');
  });

  $(document).on('click', '.koopo-ticket-guest-send', function () {
    var $card = $(this).closest('.koopo-ticket-card');
    var $guest = $(this).closest('.koopo-ticket-guest-card');
    var itemId = $card.data('item-id');
    var $btn = $(this);
    var ticketId = parseInt($btn.data('ticket-id'), 10) || 0;
    var guestIndex = parseInt($guest.data('guest-slot-index'), 10) || 0;
    var payload = {
      ticket_id: ticketId,
      guest_index: guestIndex,
      user_id: parseInt($guest.find('[data-guest-user-id]').val(), 10) || 0,
      name: $guest.find('[data-guest-name]').val(),
      email: $guest.find('[data-guest-email]').val(),
      phone: $guest.find('[data-guest-phone]').val()
    };
    $btn.prop('disabled', true).addClass('is-loading');

    request('customer/tickets/' + itemId + '/send', 'POST', payload).done(function () {
      showNotice($card, 'success', text('send_success', 'Transfer invite sent.'));
      loadTickets(currentPage);
    }).fail(function (xhr) {
      var msg = text('send_error', 'Error.');
      if (xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
      showNotice($card, 'error', msg);
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '.koopo-ticket-transfer-cancel', function () {
    var $card = $(this).closest('.koopo-ticket-card');
    var $guest = $(this).closest('.koopo-ticket-guest-card');
    var itemId = $card.data('item-id');
    var $btn = $(this);
    var ticketId = parseInt($btn.data('ticket-id'), 10) || 0;
    var guestIndex = parseInt($guest.data('guest-slot-index'), 10) || 0;

    $btn.prop('disabled', true).addClass('is-loading');
    request('customer/tickets/' + itemId + '/transfer/cancel', 'POST', {
      ticket_id: ticketId,
      guest_index: guestIndex
    }).done(function () {
      showNotice($card, 'success', text('cancel_transfer_success', 'Pending transfer cancelled.'));
      loadTickets(currentPage);
    }).fail(function (xhr) {
      var msg = text('cancel_transfer_error', 'Unable to cancel transfer.');
      if (xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
      showNotice($card, 'error', msg);
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '.koopo-ticket-unassign', function () {
    var $card = $(this).closest('.koopo-ticket-guest-card');
    $card.find('.koopo-ticket-assigned').remove();
    $card.find('.koopo-ticket-guest-editor').show();
    $card.find('[data-guest-name], [data-guest-email], [data-guest-phone]').val('');
    $card.find('[data-guest-user-id]').val('');
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
        return '<div class="koopo-ticket-friend" data-name="' + escapeAttr(friend.name || '') + '" data-email="' + escapeAttr(friend.email || '') + '" data-avatar="' + escapeAttr(friend.avatar || '') + '" data-user-id="' + escapeAttr(friend.id || 0) + '">' +
          '<span class="koopo-ticket-friend-avatar"' + backgroundStyle(friend.avatar) + '></span>' +
          '<span>' + escapeHtml(friend.name || '') + ' (' + escapeHtml(friend.email || '') + ')</span>' +
        '</div>';
      }).join('');

      $results.html(html).show();
      $spinner.hide();
    }).fail(function () {
      $results.hide().empty();
      $spinner.hide();
    });
  });

  $(document).on('click', '.koopo-ticket-friend', function () {
    var $friend = $(this);
    var $card = $friend.closest('.koopo-ticket-guest-card');
    $card.find('[data-guest-name]').val($friend.attr('data-name') || '');
    $card.find('[data-guest-email]').val($friend.attr('data-email') || '');
    $card.find('[data-guest-user-id]').val($friend.attr('data-user-id') || 0);
    $card.find('[data-guest-results]').hide().empty();
    $card.find('.koopo-ticket-friend-spinner').hide();
    $card.find('.koopo-ticket-guest-editor').hide();
    $card.find('.koopo-ticket-assigned').remove();
    $card.prepend(buildAssignedGuest({
      avatar: $friend.attr('data-avatar') || '',
      name: $friend.attr('data-name') || '',
      email: $friend.attr('data-email') || '',
      user_id: parseInt($friend.attr('data-user-id'), 10) || 0
    }, true));
  });

  $(document).on('click', '.koopo-ticket-page, .koopo-ticket-page-nav', function () {
    var page = parseInt($(this).data('page'), 10) || 0;
    if (!page || page === currentPage || page < 1 || page > totalPages) {
      return;
    }
    loadTickets(page);
  });

  $(function () {
    if (!$('#koopo-ticket-dashboard').length) {
      return;
    }
    loadTickets(1);
  });
})(jQuery);
