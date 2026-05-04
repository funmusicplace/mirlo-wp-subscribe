/* global mirloAjax */
jQuery(document).ready(function ($) {
  var $modal = $("#mirlo-modal");
  var $body = $(".mirlo-modal-body", $modal);
  var $loading = $(".mirlo-loading", $modal);

  // Format any inline server-rendered tier prices (data-amount + data-currency).
  $(".mirlo-tier-price[data-amount]").each(function () {
    var $el = $(this);
    var amount = parseFloat($el.data("amount"));
    var currency = String($el.data("currency"));
    $el.text(formatCurrency(amount, currency) + "/month");
  });

  // ── Open ──────────────────────────────────────────────────────────────
  $(document).on("click", ".mirlo-subscribe-btn", function () {
    var slug = $(this).data("artist-slug");
    openModal();
    fetchArtist(slug);
  });

  // ── Subscribe ─────────────────────────────────────────────────────────
  $(document).on("click", ".mirlo-tier", function () {
    var artistId = $(this).data("artist-id");
    var tierId = $(this).data("tier-id");
    $(this).prop("disabled", true).text("Redirecting…");

    $.ajax({
      url: mirloAjax.ajax_url,
      type: "POST",
      data: {
        action: "mirlo_subscribe",
        artist_id: artistId,
        tier_id: tierId,
        nonce: mirloAjax.nonce,
      },
      success: function (response) {
        console.log("response", response);
        if (response.success) {
          window.location.href = response.data.sessionUrl;
        } else {
          showError(JSON.stringify(response.data, null, 2));
        }
      },
      error: function () {
        showError("Network error — please try again.");
      },
    });
  });

  // ── Close ─────────────────────────────────────────────────────────────
  $(document).on(
    "click",
    ".mirlo-modal-close, .mirlo-modal-overlay",
    closeModal,
  );

  $(document).on("keydown", function (e) {
    if (e.key === "Escape") {
      closeModal();
    }
  });

  // ── API call ──────────────────────────────────────────────────────────
  function fetchArtist(slug) {
    $.ajax({
      url: mirloAjax.ajax_url,
      type: "POST",
      data: {
        action: "fetch_mirlo_artist",
        artist_slug: slug,
        nonce: mirloAjax.nonce,
      },
      success: function (response) {
        if (response.success) {
          renderModal(response.data);
        } else {
          showError(response.data || "Unknown error.");
        }
      },
      error: function () {
        showError("Network error — please try again.");
      },
    });
  }

  // ── Render ────────────────────────────────────────────────────────────
  function renderModal(data) {
    var artist = data.artist;
    var tiers = data.tiers || [];
    var artistCurrency = artist.user && artist.user.currency
      ? artist.user.currency.toUpperCase()
      : "USD";

    var avatarSrc =
      artist.avatar && artist.avatar.sizes && artist.avatar.sizes[300]
        ? artist.avatar.sizes[300]
        : "";
    $(".mirlo-artist-avatar", $modal)
      .attr("src", avatarSrc)
      .toggle(!!avatarSrc);
    $(".mirlo-artist-name", $modal).text(artist.name || "");

    var tiersHtml;
    if (tiers.length === 0) {
      tiersHtml =
        '<p class="mirlo-no-tiers">No subscription tiers available.</p>';
    } else {
      tiersHtml = tiers
        .map(function (tier) {
          var currency = tier.currency
            ? tier.currency.toUpperCase()
            : artistCurrency;
          var price =
            tier.minAmount != null
              ? formatCurrency(tier.minAmount / 100, currency) + "/month"
              : "Free";
          return (
            '<button class="mirlo-tier" data-artist-id="' +
            escAttr(String(artist.id)) +
            '" data-tier-id="' +
            escAttr(String(tier.id)) +
            '">' +
            '<div class="mirlo-tier-name">' +
            escHtml(tier.name) +
            "</div>" +
            '<div class="mirlo-tier-price">' +
            escHtml(price) +
            "</div>" +
            (tier.description
              ? '<div class="mirlo-tier-description">' +
                escHtml(tier.description) +
                "</div>"
              : "") +
            "</button>"
          );
        })
        .join("");
    }

    $(".mirlo-tiers-list", $modal).html(tiersHtml);

    $loading.addClass("hidden");
    $body.removeClass("hidden");
  }

  function showError(msg) {
    $loading.text("Error: " + msg).removeClass("hidden");
    $body.addClass("hidden");
  }

  // ── Helpers ───────────────────────────────────────────────────────────
  function openModal() {
    $loading.text("Loading…").removeClass("hidden");
    $body.addClass("hidden");
    $modal.removeClass("hidden");
    $("body").css("overflow", "hidden");
    $(".mirlo-modal-close", $modal).trigger("focus");
  }

  function closeModal() {
    $modal.addClass("hidden");
    $("body").css("overflow", "");
  }

  function formatCurrency(amount, currency) {
    try {
      return new Intl.NumberFormat(undefined, {
        style: "currency",
        currency: currency,
        minimumFractionDigits: 2,
      }).format(amount);
    } catch (e) {
      return currency + " " + amount.toFixed(2);
    }
  }

  function escHtml(str) {
    var d = document.createElement("div");
    d.textContent = String(str);
    return d.innerHTML;
  }

  function escAttr(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }
});
