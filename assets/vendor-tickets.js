(function ($) {
  'use strict';

  var api = window.KOOPO_TICKETS_VENDOR || {};
  if (!api.rest || !api.nonce) return;

  function request(path, method, data) {
    var base = api.rest.replace(/\/$/, '');
    var url = base + '/' + path.replace(/^\//, '');
    return $.ajax({
      url: url,
      method: method || 'GET',
      data: data ? JSON.stringify(data) : null,
      contentType: 'application/json',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', api.nonce);
      }
    });
  }

  function showNotice(type, message) {
    var $notice = $('#koopo-ticket-notice');
    if (!$notice.length) return;
    $notice.removeClass('is-error is-success');
    $notice.addClass(type === 'success' ? 'is-success' : 'is-error');
    $notice.text(message).show();
  }

  function clearNotice() {
    var $notice = $('#koopo-ticket-notice');
    if ($notice.length) $notice.hide().text('').removeClass('is-error is-success');
  }

  function fmtCurrency(value) {
    if (!value) return '—';
    if (api.currency_symbol) return api.currency_symbol + parseFloat(value).toFixed(2);
    return parseFloat(value).toFixed(2);
  }

  function fmtSalesWindow(start, end) {
    if (!start && !end) return '—';
    if (start && end) return start + ' → ' + end;
    return start || end;
  }

  function statusBadge(status) {
    var normalized = status || 'active';
    var css = normalized === 'inactive' ? 'koopo-tickets-badge is-inactive' : 'koopo-tickets-badge';
    return '<span class="' + css + '">' + normalized + '</span>';
  }

  function applyFilters(items) {
    var search = ($('#koopo-ticket-filter-search').val() || '').toLowerCase();
    var eventId = parseInt($('#koopo-ticket-filter-event').val() || 0, 10);
    var status = $('#koopo-ticket-filter-status').val();
    var visibility = $('#koopo-ticket-filter-visibility').val();

    return items.filter(function (item) {
      if (search && item.title.toLowerCase().indexOf(search) === -1) return false;
      if (eventId && item.event_id !== eventId) return false;
      if (status && item.status !== status) return false;
      if (visibility && item.visibility !== visibility) return false;
      return true;
    });
  }

  function renderRows(items) {
    var $body = $('#koopo-ticket-types-body');
    if (!$body.length) return;
    if (!items.length) {
      $body.html('<tr><td colspan="10">No ticket types yet.</td></tr>');
      return;
    }

    var rows = items.map(function (item) {
      var eventTitle = item.event_title || '—';
      return '<tr>' +
        '<td>' + item.title + '</td>' +
        '<td>' + eventTitle + '</td>' +
        '<td>' + fmtCurrency(item.price) + '</td>' +
        '<td>' + (item.capacity || '—') + '</td>' +
        '<td>' + statusBadge(item.status) + '</td>' +
        '<td>' + (item.visibility || 'public') + '</td>' +
        '<td>' + fmtSalesWindow(item.sales_start, item.sales_end) + '</td>' +
        '<td>' + (item.sku || '—') + '</td>' +
        '<td>' + (item.product_id ? ('#' + item.product_id) : '—') + '</td>' +
        '<td>' +
          '<button class="button koopo-edit-ticket" data-id="' + item.id + '">Edit</button> ' +
          '<button class="button koopo-delete-ticket" data-id="' + item.id + '">Delete</button>' +
        '</td>' +
      '</tr>';
    }).join('');

    $body.html(rows);
  }

  function loadTickets() {
    request('ticket-types', 'GET').done(function (items) {
      var filtered = applyFilters(items || []);
      renderRows(filtered);
    });
  }

  function loadEvents() {
    if (!api.events || !api.events.length) return;
    var $select = $('#koopo-ticket-event');
    var $filter = $('#koopo-ticket-filter-event');
    if (!$select.length) return;
    $select.empty();
    $select.append('<option value="">Select event</option>');
    if ($filter.length) {
      $filter.empty();
      $filter.append('<option value="">All events</option>');
    }
    api.events.forEach(function (ev) {
      $select.append('<option value="' + ev.id + '">' + ev.title + '</option>');
      if ($filter.length) {
        $filter.append('<option value="' + ev.id + '">' + ev.title + '</option>');
      }
    });
  }

  function bindCreate() {
    $('#koopo-ticket-create').on('submit', function (e) {
      e.preventDefault();
      clearNotice();

      var payload = {
        title: $('#koopo-ticket-title').val(),
        event_id: parseInt($('#koopo-ticket-event').val(), 10) || null,
        price: parseFloat($('#koopo-ticket-price').val() || 0),
        capacity: parseInt($('#koopo-ticket-capacity').val(), 10) || 0,
        status: $('#koopo-ticket-status').val(),
        visibility: $('#koopo-ticket-visibility').val(),
        sales_start: $('#koopo-ticket-sales-start').val(),
        sales_end: $('#koopo-ticket-sales-end').val(),
        sku: $('#koopo-ticket-sku').val()
      };

      if (!payload.title) {
        showNotice('error', 'Ticket name is required.');
        return;
      }
      if (!payload.event_id) {
        showNotice('error', 'Event is required.');
        return;
      }
      if (payload.price < 0 || payload.capacity < 0) {
        showNotice('error', 'Price and capacity must be zero or more.');
        return;
      }

      var id = $('#koopo-ticket-id').val();
      var endpoint = id ? ('ticket-types/' + id) : 'ticket-types';

      request(endpoint, 'POST', payload).done(function () {
        $('#koopo-ticket-create')[0].reset();
        $('#koopo-ticket-id').val('');
        $('#koopo-ticket-submit').text('Create Ticket Type');
        $('#koopo-ticket-cancel').hide();
        showNotice('success', id ? 'Ticket type updated.' : 'Ticket type created.');
        loadTickets();
      }).fail(function (xhr) {
        var msg = 'Could not save ticket type.';
        if (xhr && xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        showNotice('error', msg);
      });
    });
  }

  function bindDelete() {
    $('#koopo-ticket-types-body').on('click', '.koopo-delete-ticket', function () {
      var id = $(this).data('id');
      if (!id) return;
      if (!window.confirm('Delete this ticket type?')) return;
      request('ticket-types/' + id, 'DELETE').done(function () {
        loadTickets();
      });
    });
  }

  function bindEdit() {
    $('#koopo-ticket-types-body').on('click', '.koopo-edit-ticket', function () {
      clearNotice();
      var id = $(this).data('id');
      if (!id) return;

      request('ticket-types/' + id, 'GET').done(function (item) {
        if (!item || !item.id) return;
        $('#koopo-ticket-id').val(item.id);
        $('#koopo-ticket-title').val(item.title);
        $('#koopo-ticket-event').val(item.event_id || '');
        $('#koopo-ticket-price').val(item.price || '');
        $('#koopo-ticket-capacity').val(item.capacity || '');
        $('#koopo-ticket-status').val(item.status || 'active');
        $('#koopo-ticket-visibility').val(item.visibility || 'public');
        $('#koopo-ticket-sales-start').val(item.sales_start || '');
        $('#koopo-ticket-sales-end').val(item.sales_end || '');
        $('#koopo-ticket-sku').val(item.sku || '');
        $('#koopo-ticket-submit').text('Update Ticket Type');
        $('#koopo-ticket-cancel').show();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    $('#koopo-ticket-cancel').on('click', function () {
      clearNotice();
      $('#koopo-ticket-create')[0].reset();
      $('#koopo-ticket-id').val('');
      $('#koopo-ticket-submit').text('Create Ticket Type');
      $('#koopo-ticket-cancel').hide();
    });
  }

  function bindFilters() {
    $('#koopo-ticket-filter-search').on('input', function () {
      loadTickets();
    });
    $('#koopo-ticket-filter-event, #koopo-ticket-filter-status, #koopo-ticket-filter-visibility').on('change', function () {
      loadTickets();
    });
  }

  $(function () {
    loadEvents();
    loadTickets();
    bindCreate();
    bindDelete();
    bindEdit();
    bindFilters();
  });
})(jQuery);
