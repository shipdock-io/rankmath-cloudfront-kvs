/*
  CloudFront Function (cloudfront-js-2.0), viewer-request,
  with a KeyValueStore associated.
*/
import cf from 'cloudfront';

const kvs = cf.kvs('YOUR_KVS_ID');

async function handler(event) {
  const request = event.request;
  const path = request.uri.replace(/\/+$/, '') || '/';

  const redirect = (await lookup(path)) || (await lookup(path.toLowerCase()));

  if (!redirect) {
    return request;
  }

  if (!redirect.to) {
    return { statusCode: redirect.status };
  }

  return {
    statusCode: redirect.status,
    headers: { location: { value: redirect.to } },
  };
}

async function lookup(key) {
  try {
    const value = await kvs.get(key);
    return JSON.parse(value);
  } catch (e) {
    return null;
  }
}
