// The state parsers, against a state shaped like biz.yelp.com's hydration
// snapshot (window.yelp.react_apollo_state) — synthetic values, real keys.
//   node --test scripts/tests/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { leadsFromState, leadDetailFromState, parseKnown } from '../yelp-fetch-leads.mjs';

const listState = {
  'Business:BIZ1234567890abc': {
    __typename: 'Business', encid: 'BIZ1234567890abc',
    privateBizInfo: {
      __typename: 'PrivateBizInfo',
      'newLeads({"after":null,"first":2})': { __typename: 'LeadConnection', pageInfo: { endCursor: 'x', hasNextPage: true }, edges: [{ __typename: 'LeadEdge', node: { __ref: 'Lead:OPAQUE1' } }] },
      'contactedLeads({"after":null,"first":10,"leadStatuses":["ACTIVE"]})': { __typename: 'LeadConnection', pageInfo: { hasNextPage: false }, edges: [{ node: { __ref: 'Lead:OPAQUE2' } }] },
      newLeads: { __typename: 'LeadConnection', totalCount: 1 },
    },
  },
  'User:u1': { __typename: 'User', encid: 'u1', displayName: 'Pat T.' },
  'Project:p1': { __typename: 'Project', encid: 'p1', jobSummaryTitle: 'Bathroom remodeling', user: { __ref: 'User:u1' }, zip: '60068', urgency: { level: 'ASAP' } },
  'Lead:OPAQUE1': { __typename: 'Lead', encid: 'LEAD_A', status: 'NEW', needsAttention: true, project: { __ref: 'Project:p1' }, 'workflowStatus({"supportedStatuses":["NEW"]})': { status: 'NEW', displayText: 'New' }, lastEventTime: { utcDateTime: '2026-09-15T01:46:46Z' }, leadPreview: { previewText: 'Hi there!' } },
  'Lead:OPAQUE2': { __typename: 'Lead', encid: 'LEAD_B', status: 'ACTIVE', project: { __ref: 'Project:p1' }, lastEventTime: { utcDateTime: '2026-09-10T10:00:00Z' } },
};

test('the list page yields every lead the state holds, with its summary', () => {
  const { leads, hasMore } = leadsFromState(listState);
  assert.equal(hasMore, true);
  assert.deepEqual(leads.map((l) => l.encid).sort(), ['LEAD_A', 'LEAD_B']);
  const a = leads.find((l) => l.encid === 'LEAD_A');
  assert.equal(a.customerName, 'Pat T.');
  assert.equal(a.title, 'Bathroom remodeling');
  assert.equal(a.zip, '60068');
  assert.equal(a.urgency, 'ASAP');
  assert.equal(a.workflowStatus, 'NEW');
  assert.equal(a.lastEventAt, '2026-09-15T01:46:46Z');
});

const detailState = {
  'Business:BIZ1234567890abc': { __typename: 'Business', encid: 'BIZ1234567890abc', privateBizInfo: { newLeads: { totalCount: 1 } } },
  'User:u1': { __typename: 'User', encid: 'u1', displayName: 'Pat T.', displayLocation: 'Glenview, IL', reviewCount: 24 },
  'Project:p1': {
    __typename: 'Project', encid: 'p1', jobSummaryTitle: 'Bathroom remodeling', name: 'Bathroom remodeling', user: { __ref: 'User:u1' }, zip: '60068',
    urgency: { level: 'ASAP' }, description: 'What needs to be remodeled?\nBathroom', unstructuredDetails: 'Wooden window frame in the shower',
    serviceOfferings: ['Bathroom Remodeling'], summaryKeywords: ['Wooden window frame', 'Water damage'],
    surveyQuestionAnswers: [{ question: 'When do you require this service?', answers: ['As soon as possible'] }, { question: 'In what location do you need the service?', answers: ['60068'] }],
  },
  'Conversation:c1': { __typename: 'Conversation', encid: 'c1' },
  'AttachmentFile:att1': { __typename: 'AttachmentFile', encid: 'att1', url: 'https://example.invalid/att1.jpg' },
  'AttachmentFile:att2': { __typename: 'AttachmentFile', encid: 'att2', url: 'https://example.invalid/att2.jpg' },
  'Lead:LEAD_A': {
    __typename: 'Lead', encid: 'LEAD_A', status: 'NEW', project: { __ref: 'Project:p1' }, conversation: { __ref: 'Conversation:c1' },
    phoneNumberConnectionInfo: { status: 'NOT_AVAILABLE', consumerPhoneNumber: null },
    'createdAt': { __typename: 'DateTime', 'localDateTime({"forBusiness":"BIZ1234567890abc"})': '2026-09-14T20:46:44-05:00' },
    location: { city: 'Park Ridge', state: 'IL' },
    'workflowStatus({"supportedStatuses":["NEW"]})': { status: 'NEW', displayText: 'New' },
  },
};

test('the detail page yields the customer, the project, the answers, and every attachment', () => {
  const d = leadDetailFromState(detailState, 'LEAD_A');
  assert.equal(d.createdAt, '2026-09-14T20:46:44-05:00');
  assert.deepEqual(d.location, { city: 'Park Ridge', state: 'IL' });
  assert.equal(d.phone, null);
  assert.equal(d.conversationId, 'c1');
  assert.deepEqual(d.customer, { name: 'Pat T.', location: 'Glenview, IL', reviewCount: 24 });
  assert.equal(d.project.title, 'Bathroom remodeling');
  assert.deepEqual(d.project.keywords, ['Wooden window frame', 'Water damage']);
  assert.equal(d.project.answers.length, 2);
  assert.equal(d.project.answers[0].answers[0], 'As soon as possible');
  assert.deepEqual(d.attachments.map((a) => a.encid), ['att1', 'att2']);
  assert.equal(leadDetailFromState(detailState, 'NOPE'), null);
});

test('known leads parse to id → last event time', () => {
  const k = parseKnown('LEAD_A@2026-09-15T01:46:46Z,LEAD_B@,  ');
  assert.equal(k.get('LEAD_A'), '2026-09-15T01:46:46Z');
  assert.equal(k.get('LEAD_B'), '');
  assert.equal(k.size, 2);
});
