<?php

namespace App\Http\Controllers;

use App\Models\Obat;
use App\Models\RakObat;
use App\Models\DetailObat;
use App\Models\DetailStokOpname;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ObatController extends Controller
{
    public function index(Request $request)
    {
        $query = Obat::query();

        if ($request->filled('nama_obat')) {
            $query->where('nama_obat', 'like', '%' . $request->nama_obat . '%');
        }

        if ($request->filled('kategori')) {
            $query->where('kategori', $request->kategori);
        }

        $obats = $query->with('rakObat')->get();

        return view('obat.kelolaobat', compact('obats'));
    }

    public function kelolaobat(Request $request)
    {
        $query = Obat::query();

        if ($request->filled('nama_obat')) {
            $query->where('nama_obat', 'like', '%' . $request->nama_obat . '%');
        }

        if ($request->filled('kategori')) {
            $query->where('kategori', $request->kategori);
        }

        $obats = $query->get();

        return view('obat.kelolaobat', compact('obats'));
    }

    public function create()
    {
        // Ambil ID terakhir dari tabel obat
        $lastObat = Obat::orderBy('id_obat', 'desc')->first();

        if ($lastObat) {
            $nextId = (int) $lastObat->id_obat + 1;
        } else {
            $nextId = 1;
        }

        // ID detail obat pertama (misal: 1-1)
        $nextDetailId = $nextId . '-1';

        // Ambil data rak
        $raks = RakObat::all();

        // Define jenis obat options
        $jenisObatOptions = [
            'Tablet' => 'Tablet',
            'Kapsul' => 'Kapsul',
            'Sirup' => 'Sirup',
            'Salep' => 'Salep',
            'Tetes' => 'Tetes',
            'Suntik' => 'Suntik',
            'Inhaler' => 'Inhaler',
            'Supositoria' => 'Supositoria',
            'Antibiotik' => 'Antibiotik',
            'Antiseptik' => 'Antiseptik',
            'Vitamin' => 'Vitamin',
            'Herbal' => 'Herbal',
            'Lainnya' => 'Lainnya'
        ];


        // Kirim ke view
        return view('obat.createobat', compact('nextId', 'nextDetailId', 'raks', 'jenisObatOptions'));
    }



    public function store(Request $request)
    {
        $request->validate([
            'nama_obat' => 'required|string|max:255',
            'jenis_obat' => 'required|string|max:100', // Changed from kategori to jenis_obat to match form
            'id_rak' => 'required|exists:rak_obat,id_rak',
            'stok' => 'required|array',
            'stok.*' => 'required|integer|min:0',
            'harga_beli' => 'required|array',
            'harga_beli.*' => 'required|numeric|min:0',


            'tgl_kadaluarsa' => 'required|array',
            'tgl_kadaluarsa.*' => 'required|date',
        ]);

        try {
            DB::beginTransaction();

            // Calculate stok_total from all stok inputs
            $stokTotal = array_sum($request->stok);

            // Calculate average harga_beli for the main obat record
            $avgHargaBeli = 0;
            if (count($request->harga_beli) > 0) {
                $avgHargaBeli = array_sum($request->harga_beli) / count($request->harga_beli);
            }

            // Calculate harga_jual (e.g., 20% markup - adjust as needed)
            $hargaJual = $avgHargaBeli * 1.2;

            // Create main obat record
            $obat = Obat::create([
                'nama_obat' => $request->nama_obat,
                'jenis_obat' => $request->jenis_obat, // Changed from kategori to jenis_obat
                'id_rak' => $request->id_rak,
                'stok_total' => $stokTotal,
                'harga_beli' => $avgHargaBeli,
                'harga_jual' => $hargaJual,
                // Add other fields as needed
            ]);

            // Create detail_obat records
            for ($i = 0; $i < count($request->stok); $i++) {
                $obat->detailObat()->create([
                    'tgl_kadaluarsa' => $request->tgl_kadaluarsa[$i],
                    'stok' => $request->stok[$i],

                    'harga_beli' => $request->harga_beli[$i],
                    // id_detailbeli can be null initially
                ]);
            }

            DB::commit();
            return redirect()->route('obat.index')->with('success', 'Obat berhasil ditambahkan.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['msg' => 'Gagal menyimpan data: ' . $e->getMessage()]);
        }
    }

    // public function show($id)
    // {
    //     $obat = Obat::with('rakObat', 'detailObat')->findOrFail($id);
    //     return view('obat.detailobat', compact('obat'));
    // }

    public function show($id)
{
    $obat = Obat::with(['rakObat', 'detailObat' => function($query) {
        $query->orderBy('tgl_kadaluarsa', 'asc');
    }])->findOrFail($id);

    return view('obat.detailobat', compact('obat'));
}

    public function edit($id)
    {
        // Force eager loading of both the detailObat and the related detailPembelian
        $obat = Obat::with([
            'detailObat' => function ($query) {
                $query->with('detailPembelian');
            },
            'rakObat'
        ])->findOrFail($id);

        $raks = RakObat::all();

        return view('obat.editobat', compact('obat', 'raks'));
    }
    public function update(Request $request, $id)
    {
        $request->validate([
            'nama_obat' => 'required|string|max:255',
            'jenis_obat' => 'required|string|max:100',
            'rak_id' => 'required|exists:rak_obat,id_rak',
            'id_detailobat' => 'required|array',
            'stok' => 'required|array',
            'stok.*' => 'required|integer|min:0',
            'harga_beli' => 'required|array',
            'harga_beli.*' => 'required|numeric|min:0',
            'diskon' => 'required|array',
            'diskon.*' => 'required|numeric|min:0|max:100',
            'tgl_kadaluarsa' => 'required|array',
            'tgl_kadaluarsa.*' => 'required|date',
        ]);

        try {
            DB::beginTransaction();

            $obat = Obat::findOrFail($id);

            // Update data utama obat
            $obat->update([
                'nama_obat' => $request->nama_obat,
                'jenis_obat' => $request->jenis_obat,
                'id_rak' => $request->rak_id,
            ]);

            $processedDetailIds = [];
            $totalStok = 0;
            $totalHargaBeli = 0;
            $countDetails = 0;

            // Proses detail obat
            for ($i = 0; $i < count($request->stok); $i++) {
                $detailId = $request->id_detailobat[$i];
                $hargaBeli = $request->harga_beli[$i] ?? 0; // Handle possible null values
                $stok = $request->stok[$i];

                $totalStok += $stok;
                $totalHargaBeli += $hargaBeli * $stok; // Weighted average
                $countDetails += $stok;

                if (strpos($detailId, 'new-') === 0) {
                    // Create new detail
                    $newDetail = $obat->detailObat()->create([
                        'id_obat' => $obat->id_obat,
                        'stok' => $stok,
                        'harga_beli' => $hargaBeli,
                        'disc' => $request->diskon[$i],
                        'tgl_kadaluarsa' => $request->tgl_kadaluarsa[$i],
                        // id_detailbeli remains null for new entries
                    ]);
                    $processedDetailIds[] = $newDetail->id_detailobat;
                } else {
                    // Update existing detail
                    $detail = $obat->detailObat()->where('id_detailobat', $detailId)->first();
                    if ($detail) {
                        $detail->update([
                            'stok' => $stok,
                            'harga_beli' => $hargaBeli,
                            'disc' => $request->diskon[$i],
                            'tgl_kadaluarsa' => $request->tgl_kadaluarsa[$i],
                            // id_detailbeli remains as is
                        ]);
                        $processedDetailIds[] = $detailId;
                    }
                }
            }

            // Delete removed details
            $obat->detailObat()->whereNotIn('id_detailobat', $processedDetailIds)->delete();

            // Update main obat record
            // Calculate weighted average price
            $avgHargaBeli = $countDetails > 0 ? $totalHargaBeli / $countDetails : 0;
            $hargaJual = $avgHargaBeli * 1.2; // 20% markup

            $obat->update([
                'stok_total' => $totalStok,
                'harga_beli' => $avgHargaBeli,
                'harga_jual' => $hargaJual,
            ]);

            DB::commit();
            return redirect()->route('obat.index')->with('success', 'Data obat berhasil diperbarui.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['msg' => 'Gagal update data: ' . $e->getMessage()]);
        }
    }
    public function destroy($id)
    {
        try {
            $obat = Obat::findOrFail($id);
            $obat->delete();
            return redirect()->route('obat.index')->with('success', 'Data obat berhasil dihapus.');
        } catch (\Exception $e) {
            return back()->withErrors(['msg' => 'Gagal menghapus data: ' . $e->getMessage()]);
        }
    }

    public function showDiskon($id)
    {
        // Ambil data DetailObat berdasarkan id, beserta relasi ke model Obat
        $obat = DetailObat::with('obat')->findOrFail($id);

        // Mengirimkan data DetailObat ke view
        return view('obat.diskon', compact('obat'));
    }



    public function simpanDiskon(Request $request, $id)
    {
        $request->validate([
            'diskon' => 'required|numeric|min:0|max:100',
        ]);

        try {
            DB::beginTransaction();

            // Find the specific detail obat record
            $detailObat = DetailObat::with('obat')->findOrFail($id);

            // Set discount only for this specific detail record
            $detailObat->disc = $request->diskon;
            $detailObat->save();

            // We don't need to recalculate the main obat's harga_jual
            // The discount will be applied at the point of sale instead

            DB::commit();

            return redirect()->route('obat.show', $detailObat->obat->id_obat)
                ->with('success', 'Diskon berhasil diterapkan untuk ID Detail Obat ' . $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['msg' => 'Gagal menyimpan diskon: ' . $e->getMessage()]);
        }
    }

   public function getObatInfo($id_obat)
    {
        // Get expired and stok opname checked medicine details
        $expiredStokOpnameIds = DetailStokOpname::join('detail_obat', 'detail_stokopname.id_detailobat', '=', 'detail_obat.id_detailobat')
            ->where('detail_obat.id_obat', $id_obat)
            ->pluck('detail_obat.id_detailobat')
            ->toArray();

        $obat = Obat::with([
            'detailObat' => function ($query) use ($expiredStokOpnameIds) {
                $query->where('stok', '>', 0)
                    ->whereNotIn('id_detailobat', $expiredStokOpnameIds)  // Exclude expired and stok opname checked items
                    ->orderBy('tgl_kadaluarsa', 'asc'); // FIFO order
            }
        ])->findOrFail($id_obat);

        // Get the first available batch (FIFO)
        $firstBatch = $obat->detailObat->first();

        $regularPrice = $obat->harga_jual;
        $discountPercent = 0;
        $discountedPrice = $regularPrice;

        // If there's a batch with discount, apply it
        if ($firstBatch && $firstBatch->disc > 0) {
            $discountPercent = $firstBatch->disc;
            $discountedPrice = $regularPrice * (1 - ($discountPercent / 100));
        }

        // Calculate actual available stock (exclude expired items in stok opname)
        $availableStok = DetailObat::where('id_obat', $id_obat)
            ->where('stok', '>', 0)
            ->whereNotIn('id_detailobat', $expiredStokOpnameIds)
            ->sum('stok');

        return response()->json([
            'nama_obat' => $obat->nama_obat,
            'regular_price' => $regularPrice,
            'discounted_price' => $discountedPrice,
            'discount_percent' => $discountPercent,
            'has_discount' => ($discountPercent > 0),
            'stok_total' => $availableStok,  // Use available stock instead of total stock
            'first_batch_id' => $firstBatch ? $firstBatch->id_detailobat : null,
            'is_available' => ($firstBatch !== null)
        ]);
    }
}
