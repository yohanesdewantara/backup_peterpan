<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Obat;
use App\Models\DetailObat;
use App\Models\Penjualan;
use App\Models\DetailPenjualan;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        // Get data for cards
        $totalObat = Obat::count();
        $totalStok = Obat::sum('stok_total');

        // Calculate current month sales
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;
        $penjualanBulanIni = Penjualan::whereMonth('tgl_penjualan', $currentMonth)
            ->whereYear('tgl_penjualan', $currentYear)
            ->sum('total');

        // Calculate nearly expired medicine (next 30 days)
        $thirtyDaysLater = Carbon::now()->addDays(30)->toDateString();
        $today = Carbon::now()->toDateString();
        $nearExpiry = DetailObat::where('stok', '>', 0)
            ->whereBetween('tgl_kadaluarsa', [$today, $thirtyDaysLater])
            ->orderBy('tgl_kadaluarsa')
            ->with('obat')
            ->take(10)
            ->get();

        $kadaluarsaCount = DetailObat::where('stok', '>', 0)
            ->where('tgl_kadaluarsa', '<=', $thirtyDaysLater)
            ->where('tgl_kadaluarsa', '>=', $today)
            ->count();

        // Low stock items
        $lowStockItems = Obat::where('stok_total', '<', 10)
            ->orderBy('stok_total')
            ->take(10)
            ->get();

        // Get data for charts
        // 1. Sales chart data (last 6 months)
        $salesChartData = $this->getSalesChartData();

        // 2. Medicine type distribution
        $jenisObatDistribution = $this->getJenisObatDistribution();

        // Original DetailObat data for the discount table
        $detailObats = DetailObat::with('obat')->get();

        return view('home', compact(
            'totalObat',
            'totalStok',
            'penjualanBulanIni',
            'kadaluarsaCount',
            'nearExpiry',
            'lowStockItems',
            'salesChartData',
            'jenisObatDistribution',
            'detailObats'
        ));
    }

    /**
     * Get sales chart data for the last 6 months
     */
    private function getSalesChartData()
    {
        $labels = [];
        $data = [];

        // Get data for the last 6 months
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $monthName = $month->translatedFormat('M Y'); // Localized month name
            $labels[] = $monthName;

            // Get total sales for this month
            $monthlySales = Penjualan::whereMonth('tgl_penjualan', $month->month)
                ->whereYear('tgl_penjualan', $month->year)
                ->sum('total');

            $data[] = $monthlySales;
        }

        return [
            'labels' => $labels,
            'data' => $data
        ];
    }

    /**
     * Get distribution of medicine types
     */
    private function getJenisObatDistribution()
    {
        $distribution = Obat::select('jenis_obat', DB::raw('count(*) as total'))
            ->groupBy('jenis_obat')
            ->orderByDesc('total')
            ->pluck('total', 'jenis_obat')
            ->toArray();

        // Limit to top 5 categories + "Others"
        if (count($distribution) > 5) {
            $topCategories = array_slice($distribution, 0, 5, true);
            $others = array_sum(array_slice($distribution, 5, null, true));
            $topCategories['Lainnya'] = $others;
            return $topCategories;
        }

        return $distribution;
    }
}
