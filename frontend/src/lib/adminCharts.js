import { Chart, BarController, BarElement, LineController, LineElement, PointElement, DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler } from 'chart.js'

Chart.defaults.font.family = "'DM Sans', sans-serif"

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler)

export function createAdminChart(canvas, type, data, overrides = {}) {
  const doughnut = type === 'doughnut'
  const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
  return new Chart(canvas, {
    type, data,
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: reducedMotion ? false : { duration: 300 },
      color: '#61736a',
      font: { family: "'DM Sans', sans-serif", size: 11 },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: { backgroundColor: '#173d36', titleColor: '#fffdf9', bodyColor: '#fffdf9', padding: 12, cornerRadius: 10, displayColors: true },
      },
      ...(doughnut ? { cutout: '78%', radius: '96%', plugins: { legend: { display: false }, tooltip: { backgroundColor: '#173d36', padding: 12, cornerRadius: 10 } } } : {
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, maxTicksLimit: 8, color: '#61736a', font: { size: 10 } } },
          y: { beginAtZero: true, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5, color: '#61736a', font: { size: 10 } }, grid: { color: '#e8e5dd' } },
        },
      }),
      ...overrides,
    },
  })
}
