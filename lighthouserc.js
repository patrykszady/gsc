/**
 * Lighthouse CI Configuration
 * 
 * Usage:
 *   npm run lighthouse                    # Audit URLs defined below
 *   npm run lighthouse:desktop            # Desktop preset
 *   npm run lighthouse:url -- https://gs.construction/specific-page
 * 
 * Or directly:
 *   npx lhci autorun
 */

export default {
  ci: {
    collect: {
      // URLs to audit
      url: [
        'https://gs.construction/',
        'https://gs.construction/projects',
        'https://gs.construction/about',
        'https://gs.construction/contact',
        'https://gs.construction/testimonials',
        'https://gs.construction/services',
        'https://gs.construction/areas-served',
      ],
      // Number of runs per URL (more = more accurate, but slower)
      numberOfRuns: 1,
      settings: {
        // Use desktop settings by default (change to 'mobile' for mobile)
        preset: 'desktop',
        // Throttling settings
        throttling: {
          cpuSlowdownMultiplier: 1,
        },
        // Categories to audit
        onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo'],
      },
    },
    assert: {
      // Fail if scores are below these thresholds
      assertions: {
        'categories:performance': ['warn', { minScore: 0.7 }],
        'categories:accessibility': ['error', { minScore: 0.9 }],
        'categories:best-practices': ['warn', { minScore: 0.9 }],
        'categories:seo': ['error', { minScore: 0.9 }],
      },
    },
    upload: {
      // Save reports locally (not to Lighthouse CI server)
      target: 'filesystem',
      outputDir: './storage/lighthouse-reports',
      reportFilenamePattern: '%%PATHNAME%%-%%DATETIME%%.html',
    },
  },
};
