const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');

// Render the complete dashboard with a CLI-only synthetic session. This reads
// configuration, but does not save settings, submit forms or run app scripts.
const rootDir = path.join(__dirname,'..');
const html = execFileSync('php',['-r',String.raw`
require getcwd().'/bootstrap/app.php';
$_SESSION['scm_logged_in']=true;
$_SESSION['scm_user_id']=0;
$_SESSION['scm_employee_id']='QA';
$_SESSION['scm_user']='Funcionario QA';
$_SESSION['scm_user_cargo']='13';
$_SESSION['scm_last_activity']=time();
$panel=new \SCM\App\SuCasaControlServiciosInmobiliarios(\SCM\Core\App::db());
$html=$panel->renderPanel();
foreach (['scm-pqr-settings-modal'=>['public-pqr','renderDashboardPublicPqrSettingsModal'], 'scm-internal-notifications-modal'=>['internal-notifications','renderDashboardInternalNotificationsModal']] as $id=>[$kind,$method]) {
  $placeholder='<div id="'.$id.'" data-scm-lazy-settings="'.$kind.'" aria-hidden="true"></div>';
  $html=str_replace($placeholder,(new ReflectionMethod($panel,$method))->invoke($panel),$html);
}
echo $html;
`],{cwd:rootDir,encoding:'utf8',maxBuffer:10e6});

(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page=await browser.newPage({viewport:{width:1600,height:1000}});
    await page.route('**/*',route=>route.abort());
    await page.setContent(html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'').replace(/<link\b[^>]*>/gi,''));
    for (const file of ['tailwind-admin.css','tailwind-services.css','admin/01-core.css','admin/02-case-timeline.css','admin/03-public-pqr.css','admin/04-dashboard-pending.css','admin/05-guide-contracts.css','admin/06-canon-insurance-audit.css','admin/07-collection-management.css','ticket-completion.css','admin/08-modern-normalize.css']) {
      await page.addStyleTag({content:fs.readFileSync(path.join(rootDir,'public/assets/css',file),'utf8')});
    }
    for (const kind of ['termination','non-renewal']) {
      assert.equal(await page.locator('[data-scm-contract-'+kind+'-panel]').count(),1,'exactly one '+kind+' panel');
      assert(await page.locator('[data-scm-contract-'+kind+'-panel]').evaluate(el=>!!el.closest('#scm-app')));
    }
    const modalIds=['scm-ticket-topic-settings-modal','scm-pqr-settings-modal','scm-internal-notifications-modal','scm-permissions-modal'];
    for(const id of modalIds) {
      const modal=page.locator('#'+id);
      assert.equal(await modal.count(),1,'unique configuration modal '+id);
      assert(await modal.evaluate(el=>!!el.closest('#scm-app')),'modal retains dashboard style scope: '+id);
      assert.equal(await modal.isVisible(),false,'closed configuration stays hidden');
      await modal.evaluate(el=>{el.classList.add('open');el.setAttribute('aria-hidden','false');});
      const dialog=modal.locator('.scm-tw-dialog');
      const bounds=await dialog.boundingBox();
      assert(bounds.width<=1580 && bounds.x>=0 && bounds.y>=0,'configuration dialog remains bounded: '+id+' '+JSON.stringify(bounds));
      assert(await dialog.evaluate(el=>el.scrollWidth<=el.clientWidth),'no horizontal overflow');
      if(id==='scm-ticket-topic-settings-modal') {
        assert.equal(await modal.locator('.scm-ticket-topic-config-grid').evaluate(el=>getComputedStyle(el).display),'grid');
        assert.equal(await modal.locator('.scm-ticket-topic-config-grid').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length),3);
        assert.equal(await modal.locator('.scm-tw-head').evaluate(el=>getComputedStyle(el).display),'grid');
        assert.equal(await modal.locator('.scm-tw-close').evaluate(el=>getComputedStyle(el).position),'absolute');
        const screenshot=path.join(os.tmpdir(),'scm-topic-settings-fixed.png');
        await page.screenshot({path:screenshot});
        console.log('Visual QA: '+screenshot);
      }
      await modal.evaluate(el=>{el.classList.remove('open');el.setAttribute('aria-hidden','true');});
    }
    await page.setViewportSize({width:390,height:844});
    const topics=page.locator('#scm-ticket-topic-settings-modal');
    await topics.evaluate(el=>el.classList.add('open'));
    assert.equal(await topics.locator('.scm-ticket-topic-config-grid').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length),1);
    assert(await topics.locator('.scm-tw-dialog').evaluate(el=>el.scrollWidth<=el.clientWidth),'mobile configuration fits screen');
    console.log('PASS: complete dashboard keeps both contractual sections unique and all four configuration modals inside #scm-app; hidden/visible states, bounded dialogs, topic grid, close button and mobile layout.');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
