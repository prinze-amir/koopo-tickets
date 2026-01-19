(function ($) {
  'use strict';

  function openModal(target) {
    $(target).addClass('is-open');
    $('body').addClass('koopo-ticket-modal-open');
  }

  function closeModal($modal) {
    $modal.removeClass('is-open');
    $('body').removeClass('koopo-ticket-modal-open');
    $modal.find('.koopo-ticket-modal-notice').hide().text('');
  }

  function getAjaxUrl() {
    if (typeof wc_add_to_cart_params !== 'undefined') {
      return wc_add_to_cart_params.wc_ajax_url.replace('%%endpoint%%', 'add_to_cart');
    }
    return '';
  }

  function submitTicketForm($form, $button) {
    var $select = $form.find('.koopo-ticket-schedule-select');
    if ($select.length) {
      var label = $select.find('option:selected').data('label') || '';
      $form.find('input[name="koopo_ticket_schedule_label"]').val(label);
    }

    var data = $form.serialize();
    var url = getAjaxUrl();
    if (!url) return;

    $button.prop('disabled', true).addClass('loading');

    $.post(url, data, function (response) {
      if (!response) return;

      if (response.error && response.product_url) {
        window.location = response.product_url;
        return;
      }

      if (response.fragments) {
        $.each(response.fragments, function (key, value) {
          $(key).replaceWith(value);
        });
      }

      $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $button]);

      var $modal = $form.closest('.koopo-ticket-modal');
      closeModal($modal);
    }).fail(function (xhr) {
      var msg = 'Unable to add ticket to cart.';
      if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
        msg = xhr.responseJSON.data.message;
      }
      $form.find('.koopo-ticket-modal-notice').text(msg).show();
    }).always(function () {
      $button.prop('disabled', false).removeClass('loading');
    });
  }

  $(document).on('click', '.koopo-ticket-open', function (e) {
    e.preventDefault();
    openModal($(this).data('target'));
  });

  $(document).on('click', '.koopo-ticket-modal-close', function (e) {
    e.preventDefault();
    closeModal($(this).closest('.koopo-ticket-modal'));
  });

  $(document).on('click', '.koopo-ticket-modal', function (e) {
    if ($(e.target).hasClass('koopo-ticket-modal')) {
      closeModal($(this));
    }
  });

  $(document).on('submit', '.koopo-ticket-modal form', function (e) {
    e.preventDefault();
    submitTicketForm($(this), $(this).find('button[type="submit"]'));
  });

  $(document).on('change', '.koopo-ticket-schedule-select', function () {
    var label = $(this).find('option:selected').data('label') || '';
    $(this).closest('form').find('input[name="koopo_ticket_schedule_label"]').val(label);
  });
})(jQuery);
