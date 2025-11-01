import React, { useEffect } from "react";

import {
  Chart,
  LineElement,
  LinearScale,
  PointElement,
  LineController,
  CategoryScale,
  Tooltip,
} from "chart.js";

// Register the required components for Chart.js
Chart.register(
  LineElement,
  LinearScale,
  PointElement,
  LineController,
  CategoryScale,
  Tooltip
);

const DashboardChart = () => {
  useEffect(() => {
    const ctx = document.getElementById("lineChart").getContext("2d");
    const myChart = new Chart(ctx, {
      type: "line",
      data: {
        labels: [
          "Mar 21",
          "Mar 22",
          "Mar 23",
          "Mar 24",
          "Mar 25",
          "Mar 26",
          "Mar 27",
          "Mar 28",
          "Mar 29",
          "Mar 30",
          "Mar 31",
        ],
        datasets: [
          {
            label: "# of Votes",
            data: [50, 100, 15, 150, 25, 50, 100, 15, 50, 25, 75],
            backgroundColor: "rgba(255, 169, 32, 1)",
            borderColor: "rgba(255, 169, 32, 1)",
            borderWidth: 2,
            fill: true,
          },
        ],
      },
      options: {
        responsive: true,
        scales: {
          y: {
            beginAtZero: true,
          },
        },
        plugins: {
          legend: {
            display: true,
          },
          tooltip: {
            enabled: true, // Enable tooltips
            callbacks: {
              label: (context) => `${context.dataset.label}: ${context.raw}`,
            },
          },
        },
      },
    });

    // Cleanup to destroy the chart on unmount
    return () => {
      myChart.destroy();
    };
  }, []);

  return <canvas id="lineChart" width={1400} height={502} />;
};

export default DashboardChart;
