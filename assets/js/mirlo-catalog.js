/* global mirloCatalog */
/*
 * [mirlo_catalog]: format prices for the visitor's locale, start Mirlo's
 * hosted checkout when someone presses Buy or Subscribe, and (staff only)
 * sell on a card reader: merch is added to a sale that's charged in one go,
 * and paid tiers have their own "Pay on reader".
 */
(function () {
  function money(cents, currency) {
    try {
      return new Intl.NumberFormat(undefined, {
        style: "currency",
        currency: currency,
      }).format(cents / 100);
    } catch (e) {
      return currency + " " + (cents / 100).toFixed(2);
    }
  }

  function formatPrices() {
    document
      .querySelectorAll(".mirlo-catalog__price[data-amount]")
      .forEach(function (el) {
        try {
          el.textContent =
            new Intl.NumberFormat(undefined, {
              style: "currency",
              currency: el.dataset.currency,
            }).format(Number(el.dataset.amount) / 100) +
            (el.dataset.suffix || "");
        } catch (e) {
          // Keep the server's version.
        }
      });
  }

  function showError(button, message) {
    var error = button.parentNode.querySelector(".mirlo-catalog__error");
    if (error) {
      error.textContent = message;
      error.hidden = false;
    }
  }

  function buy(button) {
    var catalog = button.closest(".mirlo-catalog");
    var data = new URLSearchParams({
      nonce: mirloCatalog.nonce,
      success_url: catalog.dataset.successUrl || "",
    });
    if (button.dataset.mirloBuy === "merch") {
      data.set("action", "mirlo_purchase_merch");
      data.set("artist", catalog.dataset.artist);
      data.set("merch_id", button.dataset.merchId);
    } else {
      data.set("action", "mirlo_purchase_v2");
      data.set("artist_id", button.dataset.artistId);
      data.set("tier_id", button.dataset.tierId);
    }

    var label = button.textContent;
    button.disabled = true;
    button.textContent = "Redirecting…";
    var error = button.parentNode.querySelector(".mirlo-catalog__error");
    if (error) {
      error.hidden = true;
    }

    fetch(mirloCatalog.ajax_url, {
      method: "POST",
      credentials: "same-origin",
      body: data,
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (response) {
        if (response.success && response.data.redirectUrl) {
          window.location.href = response.data.redirectUrl;
          return;
        }
        var d = response.data || {};
        throw new Error(
          (d.message || "Couldn't start the checkout.") +
            (d.detail ? " (" + d.detail + ")" : ""),
        );
      })
      .catch(function (e) {
        button.disabled = false;
        button.textContent = label;
        showError(
          button,
          e instanceof SyntaxError || e instanceof TypeError
            ? "Network error — please try again."
            : e.message,
        );
      });
  }

  document.addEventListener("click", function (e) {
    var button = e.target.closest(".mirlo-catalog [data-mirlo-buy]");
    if (button && !button.disabled) {
      buy(button);
    }
    var cancel = e.target.closest(".mirlo-catalog__reader-cancel");
    if (cancel && !cancel.disabled) {
      cancelReaderSale(cancel.closest(".mirlo-catalog__reader-form"));
    }
    var change = e.target.closest("[data-sale-change]");
    if (change) {
      changeLine(change);
    }
    var clear = e.target.closest(".mirlo-catalog__sale-clear");
    if (clear) {
      clearSale(clear.closest(".mirlo-catalog__sale"));
    }
  });

  /* ------------------------------------------------------------------
   * Card reader sales: send the sale to the chosen reader, then check
   * every couple of seconds until the card is tapped, it's cancelled, or
   * we give up waiting.
   * ------------------------------------------------------------------ */

  var POLL_MS = 2000;

  function post(params) {
    return fetch(mirloCatalog.ajax_url, {
      method: "POST",
      credentials: "same-origin",
      body: params,
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (response) {
        if (response.success) {
          return response.data;
        }
        var d = response.data || {};
        throw new Error(
          (d.message || "Something went wrong.") +
            (d.detail ? " (" + d.detail + ")" : ""),
        );
      })
      .catch(function (e) {
        throw e instanceof SyntaxError || e instanceof TypeError
          ? new Error("Network error — please try again.")
          : e;
      });
  }

  function readerSelect(form) {
    return form
      .closest(".mirlo-catalog")
      .querySelector(".mirlo-catalog__reader-select");
  }

  function readerStorageKey(form) {
    var bar = form
      .closest(".mirlo-catalog")
      .querySelector(".mirlo-catalog__reader");
    return "mirloReader:" + (bar ? bar.dataset.artistId : "");
  }

  function readerBar(el) {
    var catalog = el.closest(".mirlo-catalog");
    return catalog && catalog.querySelector(".mirlo-catalog__reader");
  }

  function setReaderStatus(form, text, state) {
    var status = form.querySelector(".mirlo-catalog__reader-status");
    status.textContent = text;
    status.hidden = !text;
    status.dataset.state = state || "";
  }

  // Back to ready for the next sale. The last status stays visible.
  function finishReaderSale(form, text, state) {
    form._sale = null;
    setReaderStatus(form, text, state);
    form.querySelector(".mirlo-catalog__reader-cancel").hidden = true;
    resetSubmit(form);
  }

  function resetSubmit(form) {
    var submit = form.querySelector('[type="submit"]');
    submit.textContent = submit.dataset.label;
    submit.disabled = isSale(form) && !currentLines(form).length;
    if (isSale(form)) {
      renderSale(form);
    }
  }

  // Sent to the reader and not given up on yet.
  function saleInProgress(form) {
    return !!(form._sale && Date.now() < form._sale.giveUpAt);
  }

  function payOnReader(form) {
    var select = readerSelect(form);
    if (!select || !select.value) {
      setReaderStatus(form, "Choose a card reader first.", "error");
      return;
    }
    if (form.reportValidity && !form.reportValidity()) {
      return;
    }

    var catalog = form.closest(".mirlo-catalog");
    var data = new URLSearchParams(new FormData(form));
    data.set("action", "mirlo_reader_charge");
    data.set("nonce", mirloCatalog.reader_nonce);
    data.set("artist", catalog.dataset.artist);
    data.set("reader_id", select.value);
    data.set("kind", form.dataset.mirloReader);
    if (form.dataset.tierId) {
      data.set("tier_id", form.dataset.tierId);
    }
    if (isSale(form)) {
      var lines = currentLines(form);
      if (!lines.length) {
        return;
      }
      data.set(
        "cart",
        JSON.stringify(
          lines.map(function (line) {
            return {
              merch_id: line.merchId,
              qty: line.qty,
              option_ids: line.optionIds,
            };
          }),
        ),
      );
    }

    var readerName = select.options[select.selectedIndex].text.replace(
      / \(offline\)$/,
      "",
    );
    var submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    submit.textContent = "Sending…";
    setReaderStatus(form, "");
    // Nothing can change until the reader answers.
    form._sale = { pending: true, giveUpAt: Infinity };
    if (isSale(form)) {
      renderSale(form);
    }

    post(data)
      .then(function (d) {
        var sale = {
          intentId: d.intentId,
          accountId: d.accountId,
          readerId: select.value,
          readerName: readerName,
          giveUpAt: Date.now() + (d.timeout || 180) * 1000,
        };
        form._sale = sale;
        submit.textContent = "Waiting…";
        form.querySelector(".mirlo-catalog__reader-cancel").hidden = false;
        setReaderStatus(
          form,
          "Waiting for the card on " + readerName + "…",
          "waiting",
        );
        pollReaderSale(form, sale);
      })
      .catch(function (e) {
        finishReaderSale(form, e.message, "error");
      });
  }

  function pollReaderSale(form, sale) {
    setTimeout(function () {
      // Cancelled, or another sale started, in the meantime.
      if (form._sale !== sale) {
        return;
      }
      post(
        new URLSearchParams({
          action: "mirlo_reader_status",
          nonce: mirloCatalog.reader_nonce,
          intent_id: sale.intentId,
          account_id: sale.accountId,
        }),
      )
        .then(function (d) {
          if (form._sale !== sale) {
            return;
          }
          if (d.status === "succeeded") {
            var email = form.querySelector('[name="email"]');
            if (email) {
              email.value = "";
            }
            if (isSale(form)) {
              setSaleLines(form, []);
            }
            finishReaderSale(
              form,
              form.dataset.mirloReader === "tier"
                ? "Subscribed. Thank you!"
                : "Paid. Thank you!",
              "paid",
            );
            return;
          }
          if (d.status === "canceled") {
            finishReaderSale(form, "Cancelled.", "cancelled");
            return;
          }
          setReaderStatus(
            form,
            d.status === "requires_capture" || d.status === "processing"
              ? "Card read, approving…"
              : "Waiting for the card on " + sale.readerName + "…",
            "waiting",
          );
          keepPolling(form, sale);
        })
        .catch(function () {
          // A dropped request: try again until it's time to give up.
          keepPolling(form, sale);
        });
    }, POLL_MS);
  }

  function keepPolling(form, sale) {
    if (Date.now() < sale.giveUpAt) {
      pollReaderSale(form, sale);
      return;
    }
    // Stop checking, but leave Cancel so it can be cleared off the reader.
    setReaderStatus(
      form,
      "No payment yet. Check the reader, or cancel and try again.",
      "error",
    );
    resetSubmit(form);
  }

  function cancelReaderSale(form) {
    var sale = form._sale;
    if (!sale || sale.pending) {
      return;
    }
    var cancel = form.querySelector(".mirlo-catalog__reader-cancel");
    cancel.disabled = true;
    post(
      new URLSearchParams({
        action: "mirlo_reader_cancel",
        nonce: mirloCatalog.reader_nonce,
        intent_id: sale.intentId,
        account_id: sale.accountId,
        reader_id: sale.readerId,
      }),
    )
      .then(function () {
        finishReaderSale(form, "Cancelled.", "cancelled");
      })
      .catch(function (e) {
        setReaderStatus(form, e.message, "error");
      })
      .then(function () {
        cancel.disabled = false;
      });
  }

  /* ------------------------------------------------------------------
   * The sale: merch added from the cards, charged on the reader in one go.
   * Kept in sessionStorage per artist, so a reload doesn't lose it. Prices
   * here are only for showing; the server and Mirlo work out the charge.
   * ------------------------------------------------------------------ */

  function isSale(form) {
    return form.dataset.mirloReader === "cart";
  }

  function saleKey(el) {
    var bar = readerBar(el);
    return "mirloSale:" + (bar ? bar.dataset.artistId : "");
  }

  function saleLines(el) {
    try {
      var lines = JSON.parse(sessionStorage.getItem(saleKey(el)) || "[]");
      return Array.isArray(lines) ? lines : [];
    } catch (err) {
      return [];
    }
  }

  // Saves the sale and redraws it in every catalog for the same artist.
  function setSaleLines(el, lines) {
    var key = saleKey(el);
    try {
      if (lines.length) {
        sessionStorage.setItem(key, JSON.stringify(lines));
      } else {
        sessionStorage.removeItem(key);
      }
    } catch (err) {
      // Private browsing: the sale lasts until the page is left.
    }
    document.querySelectorAll(".mirlo-catalog__sale").forEach(function (form) {
      if (saleKey(form) === key) {
        form._lines = lines;
        renderSale(form);
      }
    });
  }

  function currentLines(form) {
    return form._lines || saleLines(form);
  }

  function renderSale(form) {
    var lines = currentLines(form);
    var locked = saleInProgress(form);
    var list = form.querySelector(".mirlo-catalog__sale-lines");
    var total = form.querySelector(".mirlo-catalog__sale-total");
    var submit = form.querySelector('[type="submit"]');

    list.textContent = "";
    var sum = 0;
    lines.forEach(function (line, i) {
      sum += line.price * line.qty;
      var li = document.createElement("li");
      li.className = "mirlo-catalog__sale-line";

      var name = document.createElement("span");
      name.className = "mirlo-catalog__sale-name";
      name.textContent =
        line.title + (line.detail ? " (" + line.detail + ")" : "");

      var qty = document.createElement("span");
      qty.className = "mirlo-catalog__sale-qty";
      qty.append(
        lineButton("−", "Remove one " + line.title, i, -1, locked),
        document.createTextNode(" " + line.qty + " "),
        lineButton(
          "+",
          "Add one more " + line.title,
          i,
          1,
          locked || roomFor(lines, line) < 1,
        ),
      );

      var price = document.createElement("span");
      price.className = "mirlo-catalog__sale-price";
      price.textContent = money(line.price * line.qty, line.currency);

      li.append(
        name,
        qty,
        price,
        lineButton("×", "Remove " + line.title, i, 0, locked),
      );
      list.appendChild(li);
    });

    list.hidden = !lines.length;
    form.querySelector(".mirlo-catalog__sale-empty").hidden = !!lines.length;
    form.querySelector(".mirlo-catalog__sale-clear").hidden =
      !lines.length || locked;
    total.hidden = !lines.length;
    total.textContent = lines.length
      ? "Total " + money(sum, lines[0].currency)
      : "";
    if (!form._sale) {
      submit.disabled = !lines.length;
    }
  }

  function lineButton(text, label, index, step, disabled) {
    var button = document.createElement("button");
    button.type = "button";
    button.className = "mirlo-catalog__sale-button";
    button.textContent = text;
    button.setAttribute("aria-label", label);
    button.dataset.saleChange = step;
    button.dataset.line = index;
    button.disabled = !!disabled;
    return button;
  }

  // How many more of this line fit, by what's left of the item and of its
  // variants, across every line of the sale.
  function roomFor(lines, line) {
    var room = Infinity;
    if (line.stock != null) {
      room =
        line.stock -
        countOf(lines, function (l) {
          return l.merchId === line.merchId;
        });
    }
    (line.optionStock || []).forEach(function (o) {
      room = Math.min(
        room,
        o.stock -
          countOf(lines, function (l) {
            return l.optionIds.indexOf(o.id) !== -1;
          }),
      );
    });
    return room;
  }

  function countOf(lines, match) {
    return lines.reduce(function (n, l) {
      return n + (match(l) ? l.qty : 0);
    }, 0);
  }

  function changeLine(button) {
    var form = button.closest(".mirlo-catalog__sale");
    if (!form || saleInProgress(form)) {
      return;
    }
    var lines = currentLines(form).slice();
    var i = Number(button.dataset.line);
    var step = Number(button.dataset.saleChange);
    if (!lines[i]) {
      return;
    }
    if (step === 0 || lines[i].qty + step < 1) {
      lines.splice(i, 1);
    } else if (step < 0 || roomFor(lines, lines[i]) >= step) {
      lines[i] = Object.assign({}, lines[i], { qty: lines[i].qty + step });
    }
    setReaderStatus(form, "");
    setSaleLines(form, lines);
  }

  function clearSale(form) {
    if (!form || saleInProgress(form)) {
      return;
    }
    setReaderStatus(form, "");
    setSaleLines(form, []);
  }

  function addToSale(addForm) {
    var bar = readerBar(addForm);
    var sale = bar && bar.querySelector(".mirlo-catalog__sale");
    if (!sale) {
      return;
    }
    var button = addForm.querySelector('[type="submit"]');
    if (saleInProgress(sale)) {
      flash(button, "Finish the sale on the reader first");
      return;
    }
    if (addForm.reportValidity && !addForm.reportValidity()) {
      return;
    }

    var optionIds = [];
    var names = [];
    var optionStock = [];
    var price = Number(addForm.dataset.price) || 0;
    addForm.querySelectorAll("select").forEach(function (select) {
      var option = select.options[select.selectedIndex];
      if (!option || !option.value) {
        return;
      }
      optionIds.push(option.value);
      names.push(option.dataset.name);
      price += Number(option.dataset.price) || 0;
      if (option.dataset.stock != null) {
        optionStock.push({
          id: option.value,
          stock: Number(option.dataset.stock),
        });
      }
    });

    var line = {
      key: addForm.dataset.merchId + "|" + optionIds.slice().sort().join(","),
      merchId: addForm.dataset.merchId,
      optionIds: optionIds,
      title: addForm.dataset.title,
      detail: names.join(", "),
      price: price,
      currency: addForm.dataset.currency,
      stock:
        addForm.dataset.stock != null ? Number(addForm.dataset.stock) : null,
      optionStock: optionStock,
      qty: 1,
    };

    var lines = currentLines(sale).slice();
    if (roomFor(lines, line) < 1) {
      flash(button, "No more left");
      return;
    }
    var same = lines.findIndex(function (l) {
      return l.key === line.key;
    });
    if (same === -1) {
      lines.push(line);
    } else {
      lines[same] = Object.assign({}, lines[same], {
        qty: lines[same].qty + 1,
      });
    }
    setReaderStatus(sale, "");
    setSaleLines(sale, lines);
    flash(button, "Added");
  }

  // Says what happened on the button for a moment.
  function flash(button, text) {
    clearTimeout(button._flash);
    if (!button.dataset.label) {
      button.dataset.label = button.textContent;
    }
    button.textContent = text;
    button._flash = setTimeout(function () {
      button.textContent = button.dataset.label;
    }, 1200);
  }

  document.addEventListener("submit", function (e) {
    var add = e.target.closest(".mirlo-catalog__add-form");
    if (add) {
      e.preventDefault();
      addToSale(add);
      return;
    }
    var form = e.target.closest(".mirlo-catalog__reader-form");
    if (!form) {
      return;
    }
    e.preventDefault();
    // Still waiting on the last one: cancel that first.
    if (saleInProgress(form)) {
      return;
    }
    payOnReader(form);
  });

  // Remember the chosen reader on this device.
  document.addEventListener("change", function (e) {
    if (!e.target.matches(".mirlo-catalog__reader-select")) {
      return;
    }
    try {
      localStorage.setItem(readerStorageKey(e.target), e.target.value);
    } catch (err) {
      // Private browsing: just don't remember it.
    }
  });

  function restoreReaders() {
    document
      .querySelectorAll(".mirlo-catalog__reader-select")
      .forEach(function (select) {
        try {
          var saved = localStorage.getItem(readerStorageKey(select));
          if (
            saved &&
            Array.prototype.some.call(select.options, function (o) {
              return o.value === saved;
            })
          ) {
            select.value = saved;
          }
        } catch (err) {
          // Keep the first reader.
        }
      });
  }

  // Coming back with the browser's Back button can restore the page as it
  // was left, with the button still saying "Redirecting…".
  window.addEventListener("pageshow", function (e) {
    if (!e.persisted) {
      return;
    }
    document
      .querySelectorAll(".mirlo-catalog [data-mirlo-buy][disabled]")
      .forEach(function (button) {
        button.disabled = false;
        button.textContent =
          button.dataset.mirloBuy === "merch" ? "Buy" : "Subscribe";
      });
  });

  function ready() {
    formatPrices();
    restoreReaders();
    document.querySelectorAll(".mirlo-catalog__sale").forEach(renderSale);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", ready);
  } else {
    ready();
  }
})();
