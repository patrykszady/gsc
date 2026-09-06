/**
 * Generic directory adapter.
 *
 * Attended (noVNC) run: open the signup page (or hunt for the "add your
 * business" link), prefill every field the classifier recognises, hand the
 * browser to the human for CAPTCHA / terms / Submit, then upload photos
 * wherever a file input shows up and finish with the page the listing
 * ended on.
 *
 * Automatic run (--auto, headless, used by citations:batch): same start,
 * then — for a plain listing form with enough recognised fields and no
 * CAPTCHA — accept the terms boxes, press Submit, read the outcome and
 * upload photos. Anything else (CAPTCHA, account login, claim flows, a
 * form that complained) is parked as "needs a human" with screenshots so
 * the board shows exactly what is left to do by hand.
 */
export default async function run(ctx) {
  const { page, directory, hints, auto } = ctx;
  const startUrl = hints.start_url || directory.start_url || directory.homepage;
  const status = await ctx.goto(startUrl);
  if (status === 0) {
    await ctx.shot('unreachable');
    return ctx.finish({ outcome: 'unreachable', note: `${startUrl} did not load.` });
  }
  if (status >= 400) {
    await ctx.shot('http-error');
    return ctx.finish({ outcome: 'unreachable', note: `${startUrl} returned HTTP ${status}.` });
  }
  await ctx.shot('landing');

  // On a homepage, look for the way in.
  const onHomepage = startUrl.replace(/\/+$/, '') === (directory.homepage || '').replace(/\/+$/, '');
  let first = await ctx.prefill();
  if (first.filled === 0 && (onHomepage || !hints.start_url)) {
    const moved = await ctx.clickSignupLink();
    if (moved) {
      await ctx.shot('signup-page');
      first = await ctx.prefill();
    }
  }
  await ctx.shot('prefilled');

  const noMechanism = ['none', 'dead', 'farm'].includes(directory.mechanism);
  if (first.filled === 0 && noMechanism) {
    return ctx.finish({ outcome: 'no_mechanism', note: `${directory.note || 'No business-listing form was found.'} Nothing on ${page.url()} takes a business listing.` });
  }

  const kinds = [...new Set(first.kinds)];
  const captcha = await ctx.detectCaptcha();
  const formLike = ['form', 'account'].includes(directory.mechanism) && hints.auto_submit !== false;
  const enough = first.filled >= 3 && (kinds.includes('email') || kinds.includes('business_name'));

  if (auto) {
    if (formLike && enough && !captcha) {
      const ticked = await ctx.tickTerms();
      if (ticked) ctx.log(`accepted ${ticked} terms checkbox(es)`);
      await ctx.shot('before-submit');
      const clicked = await ctx.clickSubmit();
      if (!clicked) {
        return ctx.park(`Prefilled ${first.filled} field(s) (${kinds.join(', ')}) but no submit button was recognised. Open the session, review the form and submit it.`);
      }
      await ctx.shot('after-submit');
      const outcome = await ctx.readOutcome();
      if (outcome.already) {
        return ctx.park(`The site says "${outcome.already}" — there may already be an account. Open the session, sign in and complete the listing.`);
      }
      if (outcome.success || (clicked.navigated && outcome.errors.length === 0)) {
        let uploaded = 0;
        if (directory.photos !== false) {
          uploaded = await ctx.uploadPhotosIfPossible(hints.photos_max || 20);
          if (uploaded) await ctx.shot('photos-uploaded');
        }
        await ctx.shot('final');
        return ctx.finish({
          listing_url: page.url(),
          note: (outcome.success ? `Submitted — the site said "${outcome.success}".` : 'Submitted.') + (uploaded ? ` Uploaded ${uploaded} photo(s).` : ''),
        });
      }
      return ctx.park(`Submitted the form but the site did not confirm${outcome.errors.length ? ': ' + outcome.errors.join(' · ') : '.'} Open the session and finish by hand.`);
    }
    const why = captcha
      ? `A CAPTCHA (${captcha}) guards this form.`
      : !formLike
        ? (directory.mechanism === 'claim' ? 'Claiming this profile needs you to sign in.' : 'This platform needs an account login.')
        : first.filled
          ? 'Too few fields were recognised to submit safely.'
          : 'No listing form was found on the page.';
    return ctx.park(`${why} Open the session to finish it yourself${first.filled ? ` (${first.filled} field(s) are prefilled: ${kinds.join(', ')})` : ''}.${directory.note ? ' ' + directory.note : ''}`);
  }

  const instructions = first.filled > 0
    ? `Prefilled ${first.filled} field(s) (${kinds.join(', ')}). Check them, add anything the form still wants (category, hours, terms, CAPTCHA), submit, and follow any "verify your email" step. Then click Continue in the admin.`
    : `No listing form was found on this page. Find "add your business" / "sign up" on the site, or log in to the existing profile, complete it${directory.note ? ' (' + directory.note + ')' : ''}, then click Continue in the admin.`;
  const resumed = await ctx.waitHuman(instructions);
  if (!resumed) {
    return ctx.finish({ outcome: 'timeout', note: 'Nobody continued the session before it expired.' });
  }
  await ctx.shot('after-human');

  // Anything with a file input on the page the human left us on: photos.
  if (directory.photos !== false) {
    const uploaded = await ctx.uploadPhotosIfPossible(hints.photos_max || 20);
    if (uploaded > 0) {
      await ctx.shot('photos-uploaded');
      const again = await ctx.waitHuman(`Uploaded ${uploaded} photo(s). Add captions if the site asks, save or publish them, then click Continue.`);
      if (!again) return ctx.finish({ listing_url: page.url(), note: 'Photos uploaded; the session expired before the final confirmation.' });
    }
  }

  await ctx.shot('final');
  const url = page.url();
  return ctx.finish({ listing_url: /^https?:/.test(url) ? url : null });
}
