/* global mirloAjax, mirloAjaxV2 */
jQuery(document).ready(function ($) {
  var $modal = $("#mirlo-modal-v2");
  var $body = $(".mirlo-modal-body", $modal);
  var $loading = $(".mirlo-loading", $modal);
  var currentSuccessUrl = "";

  // Format any inline server-rendered tier prices (data-amount + data-currency).
  $(".mirlo-tier-price[data-amount]").each(function () {
    var $el = $(this);
    var amount = parseFloat($el.data("amount"));
    var currency = String($el.data("currency"));
    $el.text(formatCurrency(amount, currency) + "/month");
  });

  // ── Open ──────────────────────────────────────────────────────────────
  $(document).on("click", ".mirlo-subscribe-btn-v2", function () {
    var slug = $(this).data("artist-slug");
    var color = $(this).data("btn-color") || "";
    var tierIdsRaw = $(this).data("tier-ids");
    var tierIds = tierIdsRaw
      ? String(tierIdsRaw)
          .split(",")
          .map(function (id) {
            return parseInt(id, 10);
          })
          .filter(function (id) {
            return !isNaN(id);
          })
      : null;
    currentSuccessUrl = $(this).data("success-url") || "";
    openModal(color);
    fetchArtist(slug, tierIds);
  });

  // ── Purchase (v1/purchase, hosted checkout) ─────────────────────────────
  $(document).on("click", ".mirlo-tier-v2", function () {
    var artistId = $(this).data("artist-id");
    var tierId = $(this).data("tier-id");
    $(this).prop("disabled", true).text("Redirecting…");

    $.ajax({
      url: mirloAjaxV2.ajax_url,
      type: "POST",
      data: {
        action: "mirlo_purchase_v2",
        artist_id: artistId,
        tier_id: tierId,
        success_url: currentSuccessUrl,
        nonce: mirloAjaxV2.nonce,
      },
      success: function (response) {
        if (response.success) {
          window.location.href = response.data.redirectUrl;
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
  function fetchArtist(slug, tierIds) {
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
          renderModal(response.data, tierIds);
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
  function renderModal(data, tierIds) {
    var artist = data.artist;
    var tiers = data.tiers || [];
    if (tierIds && tierIds.length) {
      tiers = tierIds
        .map(function (id) {
          return tiers.filter(function (tier) {
            return parseInt(tier.id, 10) === id;
          })[0];
        })
        .filter(Boolean);
    }
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
            '<button class="mirlo-tier-v2" data-artist-id="' +
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
  function openModal(color) {
    $modal[0].style.setProperty("--mirlo-accent", color || "");
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
      return currency + " " + amount.toFixed(2);
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
