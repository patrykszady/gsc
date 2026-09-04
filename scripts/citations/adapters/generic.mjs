/**
 * Generic directory adapter: open the signup page (or hunt for the
 * "add your business" link from the homepage), prefill every field the
 * classifier recognises, hand over to the human for CAPTCHA / terms /
 * Submit, then upload photos wherever a file input shows up and finish
 * with the page the listing ended on.
 */
export default async function run(ctx) {
  const { page, directory, hints, listing } = ctx;
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

  const instructions = first.filled > 0
    ? `Prefilled ${first.filled} field(s) (${[...new Set(first.kinds)].join(', ')}). Check them, add anything the form still wants (category, hours, terms, CAPTCHA), submit, and follow any "verify your email" step. Then click Continue in the admin.`
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
