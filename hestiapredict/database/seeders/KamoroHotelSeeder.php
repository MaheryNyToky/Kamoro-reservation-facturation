<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class KamoroHotelSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'reco1@kamorohotel.com'],
            ['name' => 'Mahery Réception', 'password' => Hash::make('reco123'), 'role' => 'receptionist']
        );

        User::updateOrCreate(
            ['email' => 'admin@kamorohotel.com'],
            ['name' => 'Directeur Kamoro', 'password' => Hash::make('admin123'), 'role' => 'admin']
        );

        User::updateOrCreate(
            ['email' => 'superadmin@kamorohotel.com'],
            ['name' => 'Super Admin Kamoro', 'password' => Hash::make('super181802'), 'role' => 'superadmin']
        );

        $roomsData = [
            ['room_number' => '01', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '02', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '03', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '04', 'type' => 'Chambre Familiale', 'model' => 'Standard', 'price' => 175000, 'fixed' => false],
            ['room_number' => '05', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '11', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '12', 'type' => 'Chambre Twin', 'model' => 'Standard', 'price' => 100000, 'fixed' => false],
            ['room_number' => '14', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '15', 'type' => 'Chambre Twin', 'model' => 'Standard', 'price' => 100000, 'fixed' => false],
            ['room_number' => '16', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '17', 'type' => 'Chambre Familiale', 'model' => 'Standard', 'price' => 175000, 'fixed' => false],
            ['room_number' => '21', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '22', 'type' => 'Chambre Triple', 'model' => 'Standard (1 Grand + 1 Petit)', 'price' => 135000, 'fixed' => false],
            ['room_number' => '23', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '24', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '25', 'type' => 'Chambre Double', 'model' => 'Standard (état dégradé)', 'price' => 95000, 'fixed' => true],
            ['room_number' => '26', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '27', 'type' => 'Chambre Double', 'model' => 'Standard', 'price' => 110000, 'fixed' => false],
            ['room_number' => '101', 'type' => 'Chambre Double', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
            ['room_number' => '102', 'type' => 'Chambre Triple', 'model' => 'Supérieure (3 Petits lits)', 'price' => 155000, 'fixed' => false],
            ['room_number' => '103', 'type' => 'Chambre Familiale', 'model' => 'Supérieure', 'price' => 205000, 'fixed' => false],
            ['room_number' => '104', 'type' => 'Chambre Familiale', 'model' => 'Supérieure', 'price' => 205000, 'fixed' => false],
            ['room_number' => '105', 'type' => 'Chambre Double', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
            ['room_number' => '106', 'type' => 'Chambre Double', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
            ['room_number' => '201', 'type' => 'Chambre Double', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
            ['room_number' => '202', 'type' => 'Chambre Double', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
            ['room_number' => '203', 'type' => 'Chambre Familiale', 'model' => 'Supérieure', 'price' => 205000, 'fixed' => false],
            ['room_number' => '204', 'type' => 'Chambre Familiale', 'model' => 'Supérieure', 'price' => 205000, 'fixed' => false],
            ['room_number' => '205', 'type' => 'Chambre Triple', 'model' => 'Supérieure (3 Petits lits)', 'price' => 155000, 'fixed' => false],
            ['room_number' => '206', 'type' => 'Chambre Twin', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
            ['room_number' => '301', 'type' => 'Chambre Double', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
            ['room_number' => '302', 'type' => 'Chambre Triple', 'model' => 'Supérieure (1 King + 1 Petit)', 'price' => 155000, 'fixed' => false],
            ['room_number' => '303', 'type' => 'Chambre Familiale', 'model' => 'Supérieure', 'price' => 205000, 'fixed' => false],
            ['room_number' => '304', 'type' => 'Chambre Familiale', 'model' => 'Supérieure', 'price' => 205000, 'fixed' => false],
            ['room_number' => '305', 'type' => 'Chambre Double', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
            ['room_number' => '306', 'type' => 'Chambre Double', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
            ['room_number' => '307', 'type' => 'Chambre Double', 'model' => 'Supérieure', 'price' => 125000, 'fixed' => false],
        ];

        foreach ($roomsData as $roomData) {
            Room::updateOrCreate(
                ['room_number' => $roomData['room_number']],
                [
                    'type' => $roomData['type'],
                    'model' => $roomData['model'],
                    'base_price_ariary' => $roomData['price'],
                    'is_fixed_price' => $roomData['fixed'],
                ]
            );
        }
    }
}
