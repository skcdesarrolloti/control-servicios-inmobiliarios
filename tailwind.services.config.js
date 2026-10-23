// Services-only utilities: no reset and no changes to the panel's existing theme.
module.exports = {
  content: ['./resources/views/public-services/*.php', './resources/views/preventive/*.php', './resources/views/metrics/*.php', './src/Modules/Metrics/MaintenanceMetricsView.php', './src/Modules/Pending/PreventiveWorkspace.php', './src/Modules/Pending/PendingView.php', './src/Modules/RentIncrease/RentIncreaseView.php', './src/Modules/OwnerCredits/OwnerCreditView.php', './public/assets/js/admin-owner-credits.js', './src/Modules/AdministrativeReports/AdministrativeReportsView.php', './public/assets/js/admin-administrative-reports.js', './src/Modules/MaintenanceOrders/MaintenanceOrdersView.php', './src/Modules/LeaseContracts/LeaseContractsView.php', './src/Modules/LeaseContracts/LeaseContractCreateView.php', './src/App/Concerns/RendersDashboard.php', './src/Modules/Pending/PublicServicesUi.php', './src/Modules/Pending/PublicServicesWorkspace.php', './public/assets/js/admin-dashboard-runtime.js'],
  // Panel-wide legacy form rules use !important and IDs; scope the utility
  // selector strongly, with Tailwind's ! modifier on each services utility.
  prefix: 'sp-', important: '#scm-app#scm-app', corePlugins: { preflight: false },
  theme: { extend: { colors: { service: { navy: '#061D49', blue: '#1E3C76', yellow: '#F8CF4A', muted: '#5A6A85', background: '#F6F8FA', lilac: '#F2F3FF' } }, fontFamily: { sans: ['Poppins', 'sans-serif'] }, boxShadow: { card: '0 4px 20px -2px rgba(6,29,73,.05),0 2px 6px -1px rgba(6,29,73,.03)' } } }
};
