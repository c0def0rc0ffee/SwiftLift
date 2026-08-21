import { useEffect, useState } from 'react';

// Surfaces a one-line "install SwiftLift" hint that the user can clear from
// the notifications dropdown. Two flavours:
//   • Browsers that fire `beforeinstallprompt` (Chrome / Edge / Android),
//     we get an `evt.prompt()` we can call to trigger the native dialog.
//   • iOS Safari, no event, so we show a "Share → Add to Home Screen" hint.
//
// Dismissals are remembered for DISMISS_DAYS so the user isn't pestered.

/**
 * <summary>
 * Narrow internal alias for the non standard `beforeinstallprompt`
 * Event that Chromium based browsers dispatch when the PWA install
 * criteria are met.
 * </summary>
 * <remarks>
 * Exposes the `prompt()` method that triggers the native install
 * dialog and the `userChoice` promise that resolves once the user
 * accepts or dismisses it.
 * </remarks>
 */
type BIPEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

/**
 * <summary>
 * `localStorage` key under which the install hint dismissal
 * timestamp is recorded.
 * </summary>
 */
const DISMISS_KEY  = 'swiftlift_install_dismissed_at';

/**
 * <summary>
 * How long, in days, a user's dismissal of the install hint should
 * suppress the prompt before it may reappear.
 * </summary>
 */
const DISMISS_DAYS = 14;

/**
 * <summary>
 * Shape returned by `useInstallHint`. Combines a visibility flag,
 * an optional native install trigger, and a dismiss action.
 * </summary>
 * <remarks>
 * `show` is true when either a `beforeinstallprompt` event is
 * queued or the iOS Safari heuristic fires. `install` is non null
 * only on platforms that expose the native dialog; on iOS the UI
 * must instead instruct the user to use the Share menu.
 * </remarks>
 */
export type InstallHint = {
  /**
   * <summary>
   * True when there is something to show, either a queued
   * `beforeinstallprompt` event or an iOS Safari hint.
   * </summary>
   */
  show: boolean;
  /**
   * <summary>
   * Native install path for Android and desktop Chromium. Null on
   * iOS, where the user has to add to home screen manually.
   * </summary>
   */
  install: (() => Promise<void>) | null;
  /**
   * <summary>
   * Hides the hint and records the dismissal for `DISMISS_DAYS`
   * before it may surface again.
   * </summary>
   */
  dismiss: () => void;
};

/**
 * <summary>
 * React hook that surfaces a one line "install SwiftLift" hint the
 * user can clear from the notifications dropdown.
 * </summary>
 * <returns>An InstallHint describing visibility, install and dismiss actions.</returns>
 * <remarks>
 * Two code paths drive `show`. On Chromium based browsers
 * (Chrome, Edge, Android) the hook listens for the
 * `beforeinstallprompt` event, captures the event object so the
 * native dialog can be triggered later, and prevents the browser
 * from showing its own prompt. On iOS Safari there is no equivalent
 * event, so the hook feature detects the iOS user agent together
 * with the absence of `display-mode: standalone` and shows a
 * "Share, then Add to Home Screen" hint instead.
 *
 * Dismissals are persisted in `localStorage` under
 * `DISMISS_KEY` along with their timestamp; the iOS branch is
 * suppressed until `DISMISS_DAYS` have elapsed. Accepting or
 * dismissing the Chromium native dialog also writes the dismissal
 * timestamp so the hint does not reappear immediately if the user
 * cancels the install.
 * </remarks>
 */
/**
 * <summary>
 * True when the user dismissed (or completed) the install hint within
 * the last <c>DISMISS_DAYS</c>, so it should stay suppressed.
 * </summary>
 */
function dismissedRecently(): boolean {
  const dismissed = localStorage.getItem(DISMISS_KEY);
  return !!dismissed && Date.now() - Number(dismissed) < DISMISS_DAYS * 86_400_000;
}

/**
 * <summary>
 * Reports whether, and how, the app can be offered for install.
 * </summary>
 * <returns>
 * An <see cref="InstallHint"/> describing which install affordance to
 * show, plus a <c>dismiss</c> callback.
 * </returns>
 * <remarks>
 * Two different mechanisms, because the platforms differ. Chromium fires
 * <c>beforeinstallprompt</c>, which is captured so the native dialog can
 * be triggered later on a real user gesture. iOS Safari fires nothing at
 * all, so it is feature-detected on mount and the user is told to use the
 * Share menu. Both paths honour a recent dismissal.
 * </remarks>
 */
export function useInstallHint(): InstallHint {
  const [evt, setEvt]                 = useState<BIPEvent | null>(null);
  const [showIosHint, setShowIosHint] = useState(false);

  // Listen for the BIP event so we can drive Chrome's native install dialog.
  useEffect(() => {
    /**
     * <summary>
     * Captures Chromium's <c>beforeinstallprompt</c> so the native install
     * dialog can be raised later, from a real user gesture.
     * </summary>
     * <param name="e">The <c>beforeinstallprompt</c> event.</param>
     * <remarks>
     * The default is prevented so the browser does not show its own mini
     * infobar; SwiftLift drives the prompt from its own UI instead.
     * </remarks>
     */
    function onBIP(e: Event) {
      e.preventDefault();
      // Chromium re-fires beforeinstallprompt on every load while the app
      // is installable, so honour a recent dismissal here too, otherwise
      // the hint (and its notification-bell badge) reappears every visit
      // even after the user clears it. Mirrors the iOS guard below.
      if (dismissedRecently()) return;
      setEvt(e as BIPEvent);
    }
    window.addEventListener('beforeinstallprompt', onBIP);
    return () => window.removeEventListener('beforeinstallprompt', onBIP);
  }, []);

  // iOS Safari path: no event, so do feature-detection on mount.
  useEffect(() => {
    if (dismissedRecently()) return;

    const isIos = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    const inStandalone =
      // @ts-expect-error: vendor-prefixed iOS Safari property
      navigator.standalone || matchMedia('(display-mode: standalone)').matches;
    if (isIos && !inStandalone) setShowIosHint(true);
  }, []);

  /**
   * <summary>
   * Suppresses the install hint and records when, so it stays hidden for
   * <c>DISMISS_DAYS</c> rather than reappearing on the next visit.
   * </summary>
   */
  function dismiss() {
    localStorage.setItem(DISMISS_KEY, String(Date.now()));
    setEvt(null);
    setShowIosHint(false);
  }

  async function install() {
    if (!evt) return;
    await evt.prompt();
    const choice = await evt.userChoice;
    if (choice.outcome === 'accepted' || choice.outcome === 'dismissed') {
      setEvt(null);
      localStorage.setItem(DISMISS_KEY, String(Date.now()));
    }
  }

  return {
    show: !!evt || showIosHint,
    install: evt ? install : null,
    dismiss,
  };
}
