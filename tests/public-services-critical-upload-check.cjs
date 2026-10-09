const assert = require('node:assert/strict');
// Retired public route rejects uploads; no business writes and no real sends.
(async () => {
  const base=process.env.SCM_SERVICES_QA_URL || 'http://127.0.0.1:9015';
  const fd=new FormData();fd.append('evidence',new Blob(['%PDF-invalid'],{type:'application/pdf'}),'test.pdf');
  const response=await fetch(base+'/public/pago-servicios-publicos.php?revision=123',{method:'POST',body:fd});
  assert.equal(response.status,405);assert.equal(response.headers.get('allow'),'GET');
  assert((await response.text()).includes('retirado'));
  const invalid=await fetch(base+'/public/pago-servicios-publicos.php?revision=123&expires=1&sig=invalid',{redirect:'manual'});
  assert.equal(invalid.status,403);
  console.log('PASS: retired payment route rejects uploads and invalid legacy links; no writes or notifications.');
})().catch(error=>{console.error(error);process.exitCode=1;});
